<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Services\AuditService;
use App\Support\FinancialPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $period = FinancialPeriod::resolve($request);
        $filters = $request->validate([
            'category' => ['nullable', Rule::in(array_keys(Expense::CATEGORIES))],
            'status' => ['nullable', Rule::in(array_keys(Expense::STATUSES))],
            'payment' => ['nullable', Rule::in(['paid', 'unpaid'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $filters += $period;
        $query = Expense::whereDate('recognized_date', '>=', $period['from'])
            ->whereDate('recognized_date', '<=', $period['to']);
        foreach (['category', 'status'] as $key) {
            if (!empty($filters[$key])) $query->where($key, $filters[$key]);
        }
        if (!empty($filters['q'])) $query->where('description', 'like', '%'.$filters['q'].'%');
        if (($filters['payment'] ?? '') === 'paid') $query->whereNotNull('paid_date');
        if (($filters['payment'] ?? '') === 'unpaid') $query->whereNull('paid_date');
        $total = (clone $query)->where('status', 'confirmed')->sum('amount');
        $expenses = $query->latest('recognized_date')->latest('id')->paginate(25)->withQueryString();
        return view('expenses.index', compact('expenses', 'filters', 'total') + $period);
    }

    public function create()
    {
        return view('expenses.form', ['expense' => new Expense, 'token' => (string) str()->uuid()]);
    }

    private function fields(Request $request): array
    {
        $data = $request->validate([
            'recognized_date' => ['required', 'date_format:Y-m-d'],
            'category' => ['required', Rule::in(array_keys(Expense::CATEGORIES))],
            'description' => ['required', 'string', 'max:500'],
            'amount' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/', 'min:0.01', 'max:999999999999.99'],
            'payment_status' => ['required', Rule::in(['paid', 'unpaid'])],
            'paid_date' => ['exclude_unless:payment_status,paid', 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'payment_method' => ['exclude_unless:payment_status,paid', 'required', Rule::in(['cash', 'transfer'])],
            'proof' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ], [], [
            'recognized_date' => 'tanggal pengakuan', 'category' => 'kategori', 'description' => 'keterangan',
            'amount' => 'nominal', 'paid_date' => 'tanggal pembayaran', 'payment_method' => 'metode pembayaran', 'proof' => 'bukti transaksi',
        ]);
        unset($data['payment_status'], $data['proof']);
        $data['paid_date'] = $data['paid_date'] ?? null;
        $data['payment_method'] = $data['payment_method'] ?? null;
        return $data;
    }

    public function store(Request $request)
    {
        $request->validate(['submission_token' => ['required', 'uuid']]);
        $data = $this->fields($request);
        $existing = Expense::where('submission_token', $request->submission_token)->first();
        if ($existing) return redirect()->route('expenses.show', $existing);
        $path = $request->hasFile('proof') ? $request->file('proof')->store('expense-proofs', 'local') : null;
        try {
            $expense = DB::transaction(function () use ($request, $data, $path) {
                $expense = Expense::create($data + ['status' => 'draft', 'created_by' => auth()->id(), 'proof_path' => $path, 'submission_token' => $request->submission_token]);
                AuditService::record('create', 'expense', 'Membuat draft beban '.$expense->number, $expense, null, $expense->toArray());
                return $expense;
            });
        } catch (\Throwable $error) {
            if ($path) Storage::disk('local')->delete($path);
            // Repeated submission tokens must not create a second expense.
            if ($existing = Expense::where('submission_token', $request->submission_token)->first()) {
                return redirect()->route('expenses.show', $existing);
            }
            throw $error;
        }
        return redirect()->route('expenses.show', $expense)->with('success', 'Draft beban berhasil disimpan.');
    }

    public function show(Expense $expense)
    {
        return view('expenses.show', ['expense' => $expense->load('createdBy', 'confirmedBy')]);
    }

    public function edit(Expense $expense)
    {
        abort_unless($expense->status === 'draft', 409, 'Hanya draft yang dapat diubah.');
        return view('expenses.form', compact('expense'));
    }

    private function locked(Expense $expense, Request $request): Expense
    {
        $row = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
        if ((int) $request->input('version') !== $row->version) {
            throw ValidationException::withMessages(['expense' => 'Data sudah berubah. Muat ulang halaman sebelum melanjutkan.']);
        }
        return $row;
    }

    public function update(Request $request, Expense $expense)
    {
        $data = $this->fields($request);
        $path = $request->hasFile('proof') ? $request->file('proof')->store('expense-proofs', 'local') : null;
        try {
            DB::transaction(function () use ($expense, $request, $data, $path) {
                $row = $this->locked($expense, $request);
                abort_unless($row->status === 'draft', 409, 'Hanya draft yang dapat diubah.');
                $old = $row->toArray();
                $row->fill($data);
                if ($path) $row->proof_path = $path;
                $row->version++;
                $row->save();
                AuditService::record('update', 'expense', 'Mengubah draft beban '.$row->number, $row, $old, $row->toArray());
            });
        } catch (\Throwable $error) {
            if ($path) Storage::disk('local')->delete($path);
            throw $error;
        }
        return redirect()->route('expenses.show', $expense)->with('success', 'Draft beban berhasil diperbarui.');
    }

    public function confirm(Request $request, Expense $expense)
    {
        DB::transaction(function () use ($expense, $request) {
            $row = $this->locked($expense, $request);
            abort_unless($row->status === 'draft', 409, 'Beban ini bukan draft.');
            $old = $row->toArray();
            $row->update(['status' => 'confirmed', 'confirmed_by' => auth()->id(), 'confirmed_at' => now(), 'version' => $row->version + 1]);
            AuditService::record('confirm', 'expense', 'Mengonfirmasi beban '.$row->number, $row, $old, $row->toArray());
        });
        return back()->with('success', 'Beban dikonfirmasi dan sudah masuk laporan.');
    }

    public function pay(Request $request, Expense $expense)
    {
        $data = $request->validate([
            'paid_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'payment_method' => ['required', Rule::in(['cash', 'transfer'])],
        ]);
        DB::transaction(function () use ($expense, $request, $data) {
            $row = $this->locked($expense, $request);
            abort_unless($row->status === 'confirmed' && !$row->paid_date, 409, 'Beban tidak dapat dilunasi ulang.');
            $old = $row->toArray();
            $row->update($data + ['version' => $row->version + 1]);
            AuditService::record('pay', 'expense', 'Mencatat pelunasan beban '.$row->number, $row, $old, $row->toArray());
        });
        return back()->with('success', 'Pelunasan dicatat tanpa menambah beban baru.');
    }

    public function void(Request $request, Expense $expense)
    {
        $data = $request->validate(['void_reason' => ['required', 'string', 'max:500']]);
        DB::transaction(function () use ($expense, $request, $data) {
            $row = $this->locked($expense, $request);
            abort_if($row->status === 'void', 409, 'Beban sudah dibatalkan.');
            $old = $row->toArray();
            $row->update($data + ['status' => 'void', 'voided_at' => now(), 'version' => $row->version + 1]);
            AuditService::record('void', 'expense', 'Membatalkan beban '.$row->number, $row, $old, $row->toArray());
        });
        return back()->with('success', 'Beban dibatalkan dan tidak dihitung dalam laporan.');
    }

    public function proof(Expense $expense)
    {
        abort_unless($expense->proof_path && Storage::disk('local')->exists($expense->proof_path), 404);
        return Storage::disk('local')->download($expense->proof_path);
    }
}
