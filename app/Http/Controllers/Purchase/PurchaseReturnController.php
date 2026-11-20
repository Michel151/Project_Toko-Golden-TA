<?php

namespace App\Http\Controllers\Purchase;

use App\Http\Controllers\Controller;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Services\PurchaseFulfillmentService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PurchaseReturnController extends Controller
{
    // Menyiapkan service yang menangani pengajuan dan tindak lanjut retur.
    public function __construct(private PurchaseFulfillmentService $fulfillment) {}

    // Menampilkan halaman utama daftar pengajuan retur beserta penerimaan bermasalah yang dapat diajukan.
    public function index(Request $request)
    {
        ['from'=>$from, 'to'=>$to] = \App\Support\MonthlyDateRange::fromRequest($request);
        $history = PurchaseReturn::query()
            ->when($from, fn ($query)=>$query->whereDate('created_at', '>=', $from))
            ->when($to, fn ($query)=>$query->whereDate('created_at', '<=', $to));
        // Menghitung kartu per status dari periode tanggal sebelum filter status daftar diterapkan.
        $statusCounts = (clone $history)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        if (in_array($request->status, ['draft', 'approved', 'rejected'], true)) {
            $history->where('status', $request->status);
        }
        $period = ['from' => $from ?? '', 'to' => $to ?? ''];
        $returns = $history->with(['purchaseReceipt.purchaseOrder.supplier', 'replacementOrder'])
            ->latest('id')->paginate(25)->withQueryString()->appends($period)->fragment('return-history');

        // Daftar penerimaan bermasalah berasal dari semua tanggal, terpisah dari filter riwayat.
        $readyReceipts = $this->claimableReceipts()
            ->with(['inspections' => fn ($query) => $query->withClaimableIssues()->withClaimedQuantities()
                ->with(['purchaseOrderItem.product', 'purchaseOrderItem.productUnit.unit', 'quarantineLocation'])])
            ->latest('receipt_date')->latest('id')
            ->paginate(25, ['*'], 'ready_page')->withQueryString()->appends($period)->fragment('ready-returns');

        $openIssues = $readyReceipts->getCollection()->mapWithKeys(fn (PurchaseReceipt $receipt) =>
            [$receipt->id => $this->openIssues($receipt)]);

        return view('purchase.returns.index', compact('returns', 'from', 'to', 'statusCounts', 'readyReceipts', 'openIssues'));
    }

    // Menyiapkan pilihan penerimaan dan masalahnya untuk form pengajuan retur.
    public function create(Request $request)
    {
        $receipts = $this->claimableReceipts()->latest('id')->get();
        $receipt = null;
        if ($id = old('purchase_receipt_id', $request->receipt_id)) {
            $receipt = $receipts->firstWhere('id', $id);
            if (! $receipt) {
                return redirect()->route('purchase.returns.create')
                    ->with('error', 'Penerimaan ini sudah selesai atau tidak memiliki sisa masalah yang dapat dibuatkan retur/klaim.');
            }
            $receipt->load(['inspections.purchaseOrderItem.product',
                'inspections.purchaseOrderItem.productUnit.unit', 'inspections.quarantineLocation']);
        }

        return view('purchase.returns.form', compact('receipt', 'receipts'));
    }

    // Mencari penerimaan yang PO-nya belum selesai dan masih memiliki sisa masalah untuk diajukan.
    private function claimableReceipts(): Builder
    {
        return PurchaseReceipt::with('purchaseOrder.supplier')
            ->whereHas('purchaseOrder', fn ($query) => $query->where('status', '!=', 'completed'))
            ->whereHas('inspections', fn ($query) => $query->withClaimableIssues());
    }

    // Menyusun nama barang, jenis masalah, dan sisa jumlah pengajuan untuk ditampilkan.
    private function openIssues(PurchaseReceipt $receipt): Collection
    {
        return $receipt->inspections->flatMap(function ($inspection) {
            $issues = [];
            foreach (PurchaseReturnItem::KINDS as $kind => $label) {
                $quantity = $inspection->claimable($kind);
                if ($quantity <= 0) {
                    continue;
                }
                $issues[] = [
                    'product' => $inspection->purchaseOrderItem->product->name,
                    'label' => $label,
                    'quantity' => $quantity,
                    'unit' => $inspection->purchaseOrderItem->productUnit?->unit?->name,
                    'location' => $kind === 'missing' ? null : $inspection->quarantineLocation?->name,
                ];
            }

            return $issues;
        });
    }

    // Memvalidasi form dan meminta service menyimpan draf retur beserta rinciannya.
    public function store(Request $request)
    {
        $data = $request->validate([
            'submission_token' => 'nullable|uuid',
            'purchase_receipt_id' => 'required|exists:purchase_receipts,id', 'notes' => 'nullable|string|max:5000',
            'items' => 'required|array|min:1|max:1500',
            'items.*.purchase_inspection_id' => 'required|exists:purchase_inspections,id',
            'items.*.kind' => ['required', Rule::in(array_keys(PurchaseReturnItem::KINDS))],
            'items.*.quantity' => 'required|numeric|min:0|max:999999999|decimal:0,4',
        ]);
        try {
            $return = $this->fulfillment->createReturn($data, (int) auth()->id());
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['items'=>'Pembuatan retur/klaim lain sedang diproses. Tunggu sebentar, lalu coba kembali.']);
        }

        return redirect()->route('purchase.returns.show', $return)->with('success', 'Draf retur/klaim berhasil disimpan dan menunggu persetujuan untuk penerbitan PO Retur.');
    }

    // Menampilkan rincian retur, persetujuan, penyerahan fisik, dan PO pengganti.
    public function show(PurchaseReturn $return)
    {
        $return->load(['purchaseReceipt.purchaseOrder.supplier', 'items.purchaseInspection.purchaseOrderItem.product',
            'items.purchaseInspection.purchaseOrderItem.productUnit.unit', 'items.purchaseInspection.quarantineLocation',
            'replacementOrder.items.product', 'createdBy', 'approvedBy', 'dispatchedBy']);

        return view('purchase.returns.show', compact('return'));
    }

    // Meminta service menyetujui retur dan menerbitkan PO pengganti.
    public function approve(PurchaseReturn $return)
    {
        $this->fulfillment->approve($return, (int) auth()->id());

        return back()->with('success', 'Pengajuan retur/klaim disetujui. PO Retur telah diterbitkan tanpa tagihan tambahan.');
    }

    // Memvalidasi alasan dan meminta service menolak pengajuan retur.
    public function reject(Request $request, PurchaseReturn $return)
    {
        $request->validate(['rejection_reason' => 'required|string|max:2000']);
        $this->fulfillment->reject($return, $request->rejection_reason);

        return back()->with('success', 'Pengajuan retur/klaim ditolak. Pengajuan baru dapat dibuat setelah data dikoreksi.');
    }

    // Meminta service mencatat penyerahan barang retur fisik kepada supplier.
    public function dispatch(PurchaseReturn $return)
    {
        $this->fulfillment->dispatch($return, (int) auth()->id());

        return back()->with('success', 'Penyerahan barang retur kepada supplier dicatat. Stok jual tidak berubah.');
    }
}
