<?php

namespace App\Http\Controllers\Purchase;

use App\Http\Controllers\Controller;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Services\PurchaseFulfillmentService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PurchaseReturnController extends Controller
{
    public function __construct(private PurchaseFulfillmentService $fulfillment) {}

    public function index(Request $request)
    {
        ['from'=>$from, 'to'=>$to] = \App\Support\MonthlyDateRange::fromRequest($request);
        $query = PurchaseReturn::with(['purchaseReceipt.purchaseOrder.supplier', 'replacementOrder']);
        $query->when($from, fn ($query)=>$query->whereDate('created_at', '>=', $from))
            ->when($to, fn ($query)=>$query->whereDate('created_at', '<=', $to));
        if (in_array($request->status, ['draft', 'approved', 'rejected'], true)) {
            $query->where('status', $request->status);
        }
        $returns = $query->latest('id')->paginate(20)->withQueryString()->appends(['from'=>$from ?? '', 'to'=>$to ?? '']);

        return view('purchase.returns.index', compact('returns', 'from', 'to'));
    }

    public function create(Request $request)
    {
        $receipts = PurchaseReceipt::with('purchaseOrder.supplier')
            ->whereHas('purchaseOrder', fn ($query) => $query->where('status', '!=', 'completed'))
            ->whereHas('inspections', fn ($query) => $query->withClaimableIssues())
            ->latest('id')->get();
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

    public function show(PurchaseReturn $return)
    {
        $return->load(['purchaseReceipt.purchaseOrder.supplier', 'items.purchaseInspection.purchaseOrderItem.product',
            'items.purchaseInspection.purchaseOrderItem.productUnit.unit', 'items.purchaseInspection.quarantineLocation',
            'replacementOrder.items.product', 'createdBy', 'approvedBy', 'dispatchedBy']);

        return view('purchase.returns.show', compact('return'));
    }

    public function approve(PurchaseReturn $return)
    {
        $this->fulfillment->approve($return, (int) auth()->id());

        return back()->with('success', 'Pengajuan retur/klaim disetujui. PO Retur telah diterbitkan tanpa tagihan tambahan.');
    }

    public function reject(Request $request, PurchaseReturn $return)
    {
        $request->validate(['rejection_reason' => 'required|string|max:2000']);
        $this->fulfillment->reject($return, $request->rejection_reason);

        return back()->with('success', 'Pengajuan retur/klaim ditolak. Pengajuan baru dapat dibuat setelah data dikoreksi.');
    }

    public function dispatch(PurchaseReturn $return)
    {
        $this->fulfillment->dispatch($return, (int) auth()->id());

        return back()->with('success', 'Penyerahan barang retur kepada supplier dicatat. Stok jual tidak berubah.');
    }
}
