<?php

namespace App\Http\Controllers\Purchase;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use Illuminate\Http\Request;

class PurchaseReceiptController extends Controller
{
    public function __construct(protected \App\Services\PurchaseFulfillmentService $fulfillment) {}

    public function index(Request $request)
    {
        $query = PurchaseReceipt::with(['purchaseOrder.supplier', 'receivedBy', 'items.location']);
        $receivableOrders = PurchaseOrder::with(['supplier', 'items.product', 'items.productUnit.unit'])
            ->whereIn('status', ['pending', 'partial'])
            ->whereHas('items', fn ($itemQuery) => $itemQuery->whereRaw('quantity_received + quantity_reserved < quantity_ordered'))
            ->latest('order_date')
            ->get();

        if ($request->filled('from')) {
            $query->whereDate('receipt_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('receipt_date', '<=', $request->to);
        }

        $summaryReceipts = (clone $query)->get();
        $receiptCount = $summaryReceipts->count();
        $allocationCount = $summaryReceipts->sum(fn ($receipt) => $receipt->items->count());
        $supplierCount = $summaryReceipts->pluck('purchaseOrder.supplier_id')->unique()->count();
        $receipts = $query->latest('receipt_date')->paginate(15)->withQueryString();

        return view('purchase.receipts.index', compact(
            'receipts', 'receiptCount', 'allocationCount', 'supplierCount', 'receivableOrders'
        ));
    }

    public function create(Request $request)
    {
        // Bisa dari detail PO atau pilih sendiri
        $pendingOrders = PurchaseOrder::with('supplier')
            ->whereIn('status', ['pending', 'partial'])
            ->orderBy('order_date')
            ->get();

        $selectedOrder = null;
        if ($request->filled('order_id') || old('purchase_order_id')) {
            $selectedOrder = PurchaseOrder::with(['supplier', 'items.product.buyUnit', 'items.productUnit.unit'])
                ->findOrFail(old('purchase_order_id', $request->order_id));
        }

        $locations = Location::orderBy('name')->get();

        return view('purchase.receipts.form', compact('pendingOrders', 'selectedOrder', 'locations'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'submission_token' => 'nullable|uuid',
            'purchase_order_id' => 'required|exists:purchase_orders,id',
            'receipt_date' => 'required|date',
            'notes' => 'nullable|string|max:5000',
            'items' => 'required|array|min:1|max:500',
            'items.*.purchase_order_item_id' => 'required|distinct|exists:purchase_order_items,id',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.product_unit_id' => 'required|exists:product_units,id',
            'items.*.quantity_received' => 'required|numeric|min:0|max:999999999|decimal:0,4',
            'items.*.quantity_damaged' => 'nullable|numeric|min:0|max:999999999|decimal:0,4',
            'items.*.quantity_wrong' => 'nullable|numeric|min:0|max:999999999|decimal:0,4',
            'items.*.quantity_missing' => 'nullable|numeric|min:0|max:999999999|decimal:0,4',
            'items.*.reason' => 'nullable|string|max:2000',
            'items.*.quarantine_location_id' => 'nullable|exists:locations,id',
            'items.*.expired_date' => 'nullable|date',
            'items.*.allocations' => 'nullable|array|max:100',
            'items.*.allocations.*.location_id' => 'required|exists:locations,id',
            'items.*.allocations.*.quantity' => 'required|numeric|min:0|max:999999999|decimal:0,4',
            'items.*.batches' => 'sometimes|array|max:100',
            'items.*.batches.*.location_id' => 'nullable|exists:locations,id',
            'items.*.batches.*.quantity' => 'required|numeric|min:0|max:999999999|decimal:0,4',
            'items.*.batches.*.expired_date' => 'nullable|date_format:Y-m-d',
            'items.*.batches.*.batch_code' => 'nullable|string|max:100',
        ]);
        $receipt = $this->fulfillment->receive($data, (int) auth()->id());

        return redirect()->route('purchase.receipts.show', $receipt)
            ->with('success', 'Pemeriksaan penerimaan dicatat. Hanya barang baik yang masuk stok jual.');
    }

    public function show(PurchaseReceipt $receipt)
    {
        $receipt->load([
            'purchaseOrder.supplier',
            'receivedBy',
            'items.product.buyUnit',
            'items.productUnit.unit',
            'items.location',
            'items.purchaseOrderItem',
            'items.stockBatch',
            'inspections.purchaseOrderItem.product', 'inspections.purchaseOrderItem.productUnit.unit',
            'inspections.quarantineLocation', 'returns.replacementOrder',
        ]);

        return view('purchase.receipts.show', compact('receipt'));
    }

    // AJAX: get PO items untuk form penerimaan
    public function getOrderItems(PurchaseOrder $order)
    {
        $order->load(['items' => function ($q) {
            $q->with('product.buyUnit')
                ->with('productUnit.unit')
                ->whereRaw('quantity_received + quantity_reserved < quantity_ordered');
        }]);

        return response()->json($order->items->map(fn ($item) => [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'product_name' => $item->product->name,
            'is_expirable' => (bool) $item->product->is_expirable,
            'product_unit_id' => $item->product_unit_id,
            'unit_name' => $item->productUnit->unit->name ?? $item->product->buyUnit->name,
            'quantity_ordered' => $item->quantity_ordered,
            'quantity_received' => $item->quantity_received,
            'remaining' => $item->receivableQuantity(),
        ]));
    }
}
