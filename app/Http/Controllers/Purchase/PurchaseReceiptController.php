<?php

namespace App\Http\Controllers\Purchase;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseReceiptController extends Controller
{
    public function __construct(protected StockService $stockService) {}

    public function index(Request $request)
    {
        $query = PurchaseReceipt::with(['purchaseOrder.supplier', 'receivedBy', 'items.location']);

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
            'receipts', 'receiptCount', 'allocationCount', 'supplierCount'
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
        if ($request->filled('order_id')) {
            $selectedOrder = PurchaseOrder::with(['supplier', 'items.product.buyUnit', 'items.productUnit.unit'])
                ->findOrFail($request->order_id);
        }

        $locations = Location::orderBy('name')->get();

        return view('purchase.receipts.form', compact('pendingOrders', 'selectedOrder', 'locations'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'purchase_order_id' => 'required|exists:purchase_orders,id',
            'receipt_date' => 'required|date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.purchase_order_item_id' => 'required|exists:purchase_order_items,id',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.product_unit_id' => 'required|exists:product_units,id',
            'items.*.quantity_received' => 'required|numeric|min:0.01',
            'items.*.expired_date' => 'nullable|date',
            'items.*.allocations' => 'required|array|min:1',
            'items.*.allocations.*.location_id' => 'required|exists:locations,id',
            'items.*.allocations.*.quantity' => 'required|numeric|min:0',
        ]);

        foreach ($request->items as $item) {
            $allocations = collect($item['allocations'])->filter(fn ($allocation) => (float) $allocation['quantity'] > 0);
            $allocatedTotal = (float) $allocations->sum('quantity');
            $product = Product::find($item['product_id']);

            if ($product?->is_expirable && empty($item['expired_date'])) {
                return back()->withInput()->with('error', "Tanggal kadaluarsa wajib diisi untuk {$product->name}.");
            }

            if (abs($allocatedTotal - (float) $item['quantity_received']) > 0.0001) {
                return back()->withInput()->with('error', 'Total alokasi lokasi harus sama dengan jumlah barang yang diterima.');
            }
            if ($allocations->pluck('location_id')->duplicates()->isNotEmpty()) {
                return back()->withInput()->with('error', 'Satu lokasi tidak boleh dipilih lebih dari sekali untuk barang yang sama.');
            }
        }

        DB::transaction(function () use ($request) {
            $receipt = PurchaseReceipt::create([
                'purchase_order_id' => $request->purchase_order_id,
                'receipt_date' => $request->receipt_date,
                'received_by' => auth()->id(),
                'notes' => $request->notes,
            ]);

            $po = PurchaseOrder::find($request->purchase_order_id);

            foreach ($request->items as $item) {
                if (empty($item['quantity_received']) || $item['quantity_received'] <= 0) {
                    continue;
                }

                // Validasi tidak melebihi sisa
                $poItem = $po->items()->find($item['purchase_order_item_id']);
                if (! $poItem || (int) $poItem->product_id !== (int) $item['product_id'] || (int) $poItem->product_unit_id !== (int) $item['product_unit_id']) {
                    throw new \Exception('Item penerimaan tidak sesuai dengan Purchase Order.');
                }
                $remaining = $poItem->remainingQuantity();
                if ((float) $item['quantity_received'] > $remaining + 0.0001) {
                    throw ValidationException::withMessages([
                        'items' => "Jumlah penerimaan {$poItem->product->name} melebihi sisa PO.",
                    ]);
                }
                $qtyReceived = (float) $item['quantity_received'];

                if ($qtyReceived <= 0) {
                    continue;
                }

                $product = Product::find($item['product_id']);
                $productUnit = $poItem->productUnit;
                $product->update(['buy_price' => $poItem->buy_price]);
                foreach ($item['allocations'] as $allocation) {
                    $allocationQty = (float) $allocation['quantity'];
                    if ($allocationQty <= 0) {
                        continue;
                    }

                    $location = Location::findOrFail($allocation['location_id']);
                    $receiptItem = PurchaseReceiptItem::create([
                        'purchase_receipt_id' => $receipt->id,
                        'purchase_order_item_id' => $poItem->id,
                        'product_id' => $product->id,
                        'product_unit_id' => $productUnit?->id,
                        'location_id' => $location->id,
                        'quantity_received' => $allocationQty,
                        'expired_date' => $product->is_expirable ? $item['expired_date'] : null,
                    ]);
                    if ($productUnit) {
                        $this->stockService->receiveStockUsingUnit(
                            $product, $productUnit, $location, $allocationQty, $receipt,
                            $receipt->receipt_date, $po->po_number,
                            $product->is_expirable ? $item['expired_date'] : null,
                            $receiptItem
                        );
                    } else {
                        $this->stockService->receiveStock(
                            $product, $location, $allocationQty, $receipt,
                            $receipt->receipt_date, $po->po_number,
                            $product->is_expirable ? $item['expired_date'] : null,
                            $receiptItem
                        );
                    }
                }

                $poItem->increment('quantity_received', $qtyReceived);
            }

            // Update status PO
            $po->refresh();
            $po->updateStatus();
        });

        return redirect()->route('purchase.receipts.index')
            ->with('success', 'Penerimaan barang berhasil dicatat. Stok telah diperbarui.');
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
        ]);

        return view('purchase.receipts.show', compact('receipt'));
    }

    // AJAX: get PO items untuk form penerimaan
    public function getOrderItems(PurchaseOrder $order)
    {
        $order->load(['items' => function ($q) {
            $q->with('product.buyUnit')
                ->with('productUnit.unit')
                ->whereRaw('quantity_received < quantity_ordered');
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
            'remaining' => $item->remainingQuantity(),
        ]));
    }
}
