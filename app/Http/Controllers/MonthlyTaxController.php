<?php

namespace App\Http\Controllers;

use App\Models\MonthlyTax;
use App\Services\AuditService;
use App\Support\FinancialPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MonthlyTaxController extends Controller
{
    public function index(Request $request)
    {
        $period = FinancialPeriod::resolve($request);
        $taxes = MonthlyTax::with('confirmedBy')->whereBetween('month', [substr($period['from'], 0, 7), substr($period['to'], 0, 7)])
            ->orderByDesc('month')->paginate(12)->withQueryString();
        return view('taxes.index', $period + compact('taxes'));
    }

    public function create(Request $request)
    {
        $request->validate(['month' => 'nullable|date_format:Y-m']);
        return view('taxes.form', ['tax' => new MonthlyTax(['month' => $request->input('month', now('Asia/Jakarta')->format('Y-m'))])]);
    }

    private function fields(Request $request): array
    {
        return $request->validate([
            'amount' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/', 'min:0', 'max:999999999999.99'],
            'description' => 'required|string|max:500',
            'proof' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ], [], ['amount' => 'nominal beban pajak', 'description' => 'keterangan', 'proof' => 'dokumen pendukung']);
    }

    public function store(Request $request)
    {
        $request->validate(['month' => 'required|date_format:Y-m|unique:monthly_taxes,month']);
        $data = $this->fields($request); unset($data['proof']);
        $path = $request->hasFile('proof') ? $request->file('proof')->store('tax-proofs', 'local') : null;
        try {
            $tax = DB::transaction(function () use ($request, $data, $path) {
                $tax = MonthlyTax::create($data + ['month' => $request->month, 'proof_path' => $path, 'created_by' => auth()->id()]);
                $this->record($tax, 'create');
                return $tax;
            });
        } catch (\Throwable $error) {
            if ($path) Storage::disk('local')->delete($path);
            if (MonthlyTax::where('month', $request->month)->exists()) throw ValidationException::withMessages(['month' => 'Pajak untuk bulan ini sudah dicatat. Buka catatan yang sudah ada.']);
            throw $error;
        }
        return redirect()->route('taxes.show', $tax)->with('success', 'Draf pajak berhasil disimpan.');
    }

    public function show(MonthlyTax $tax)
    {
        $tax->load('createdBy', 'confirmedBy');
        $revisions = $tax->revisions()->with('createdBy')->latest('id')->paginate(20);
        return view('taxes.show', compact('tax', 'revisions'));
    }

    public function edit(MonthlyTax $tax) { return view('taxes.form', compact('tax')); }

    private function locked(Request $request, MonthlyTax $tax): MonthlyTax
    {
        $request->validate(['version' => 'required|integer|min:1']);
        $row = MonthlyTax::whereKey($tax->id)->lockForUpdate()->firstOrFail();
        if ((int) $request->version !== $row->version) throw ValidationException::withMessages(['tax' => 'Data sudah berubah. Muat ulang halaman sebelum melanjutkan.']);
        return $row;
    }

    private function record(MonthlyTax $tax, string $action, ?string $reason = null, ?array $before = null): void
    {
        $tax->revisions()->create(['snapshot' => $tax->attributesToArray(), 'action' => $action, 'reason' => $reason,
            'created_by' => auth()->id(), 'created_at' => now()]);
        AuditService::record($action, 'monthly_tax', 'Pencatatan pajak '.$tax->month, $tax, $before, $tax->attributesToArray());
    }

    public function update(Request $request, MonthlyTax $tax)
    {
        $data = $this->fields($request); unset($data['proof']);
        $request->validate(['correction_reason' => 'nullable|string|max:500', 'month' => 'prohibited']);
        $path = $request->hasFile('proof') ? $request->file('proof')->store('tax-proofs', 'local') : null;
        try {
            DB::transaction(function () use ($request, $tax, $data, $path) {
                $row = $this->locked($request, $tax);
                $reason = trim((string) $request->correction_reason);
                if ($row->status === 'confirmed' && $reason === '') throw ValidationException::withMessages(['correction_reason' => 'Alasan koreksi wajib diisi untuk pajak yang sudah dikonfirmasi.']);
                $before = $row->attributesToArray();
                $row->fill($data + ['status' => 'draft', 'confirmed_by' => null, 'confirmed_at' => null]);
                if ($path) $row->proof_path = $path;
                $row->version++; $row->save();
                $this->record($row, 'update', $reason ?: null, $before);
            });
        } catch (\Throwable $error) {
            if ($path) Storage::disk('local')->delete($path);
            throw $error;
        }
        return redirect()->route('taxes.show', $tax)->with('success', 'Draf diperbarui. Konfirmasi kembali agar masuk laporan.');
    }

    public function confirm(Request $request, MonthlyTax $tax)
    {
        $request->validate(['confirmation' => 'accepted']);
        DB::transaction(function () use ($request, $tax) {
            $row = $this->locked($request, $tax);
            abort_unless($row->status === 'draft', 409, 'Pajak sudah dikonfirmasi.');
            $before = $row->attributesToArray();
            $row->update(['status' => 'confirmed', 'confirmed_by' => auth()->id(), 'confirmed_at' => now(), 'version' => $row->version + 1]);
            $this->record($row, 'confirm', null, $before);
        });
        return back()->with('success', 'Beban pajak dikonfirmasi dan masuk laporan laba rugi.');
    }

    public function proof(Request $request, MonthlyTax $tax)
    {
        $request->validate(['revision' => 'nullable|integer|min:1']);
        $path = $request->filled('revision') ? $tax->revisions()->findOrFail($request->revision)->snapshot['proof_path'] ?? null : $tax->proof_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        return Storage::disk('local')->download($path);
    }
}
