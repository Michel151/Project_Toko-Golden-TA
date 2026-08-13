<?php

namespace App\Http\Controllers\Purchase;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseOrder::with(['supplier', 'createdBy', 'items']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        if ($request->filled('from')) {
            $query->whereDate('order_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('order_date', '<=', $request->to);
        }

        $summaryOrders = (clone $query)->get();
        $totalOrderValue = $summaryOrders->sum(fn ($order) => $order->totalValue());
        $pendingCount = $summaryOrders->where('status', 'pending')->count();
        $partialCount = $summaryOrders->where('status', 'partial')->count();
        $completedCount = $summaryOrders->where('status', 'completed')->count();
        $orders    = $query->latest('order_date')->paginate(15)->withQueryString();
        $suppliers = Supplier::orderBy('name')->get();

        return view('purchase.orders.index', compact(
            'orders', 'suppliers', 'totalOrderValue', 'pendingCount',
            'partialCount', 'completedCount'
        ));
    }

    public function create()
    {
        $suppliers = Supplier::orderBy('name')->get();
        $selectedSupplierId = old('supplier_id');
        $products = $selectedSupplierId
            ? Supplier::with(['products.buyUnit', 'products.productUnits.unit'])->find($selectedSupplierId)?->products ?? collect()
            : collect();
        $supplierProductsJson = $this->supplierProductsJson();

        return view('purchase.orders.form', compact('suppliers', 'products', 'supplierProductsJson'));
    }

    public function store(Request $request)
    {
        $this->normalizeItemPrices($request);

        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'order_date'  => 'required|date',
            'items'       => 'required|array|min:1',
            'items.*.product_id'       => 'required|exists:products,id',
            'items.*.product_unit_id'  => 'required|exists:product_units,id',
            'items.*.quantity_ordered' => 'required|numeric|min:0.01',
            'items.*.buy_price'        => 'required|numeric|min:0',
        ]);

        if (! $this->itemsBelongToSupplier((int) $validated['supplier_id'], $validated['items'])) {
            return back()
                ->withErrors(['items' => 'Daftar barang hanya boleh berisi barang dari supplier yang dipilih.'])
                ->withInput();
        }

        if (! $this->unitsBelongToProducts($validated['items'])) {
            return back()
                ->withErrors(['items' => 'Satuan beli harus sesuai dengan barang yang dipilih.'])
                ->withInput();
        }

        DB::transaction(function () use ($validated) {
            $po = PurchaseOrder::create([
                'po_number'   => PurchaseOrder::generatePoNumber(),
                'supplier_id' => $validated['supplier_id'],
                'order_date'  => $validated['order_date'],
                'status'      => 'pending',
                'created_by'  => auth()->id(),
            ]);

            foreach ($validated['items'] as $item) {
                if (empty($item['product_id'])) continue;
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id'        => $item['product_id'],
                    'product_unit_id'   => $item['product_unit_id'],
                    'quantity_ordered'  => $item['quantity_ordered'],
                    'quantity_received' => 0,
                    'buy_price'         => $item['buy_price'],
                ]);

                DB::table('product_units')
                    ->where('id', $item['product_unit_id'])
                    ->where('product_id', $item['product_id'])
                    ->update(['buy_price' => $item['buy_price']]);
            }
        });

        return redirect()->route('purchase.orders.index')
                         ->with('success', 'Purchase Order berhasil dibuat.');
    }

    public function show(PurchaseOrder $order)
    {
        $order->load(['supplier', 'createdBy', 'items.product.buyUnit', 'items.productUnit.unit', 'receipts.items.location', 'receipts.receivedBy']);
        return view('purchase.orders.show', compact('order'));
    }

    public function edit(PurchaseOrder $order)
    {
        if ($order->status !== 'pending') {
            return back()->with('error', 'PO yang sudah diproses tidak bisa diedit.');
        }
        $order->load('items.product.productUnits.unit');
        $suppliers = Supplier::orderBy('name')->get();
        $selectedSupplierId = old('supplier_id', $order->supplier_id);
        $products = Supplier::with(['products.buyUnit', 'products.productUnits.unit'])->find($selectedSupplierId)?->products ?? collect();
        $supplierProductsJson = $this->supplierProductsJson();

        return view('purchase.orders.form', compact('order', 'suppliers', 'products', 'supplierProductsJson'));
    }

    public function update(Request $request, PurchaseOrder $order)
    {
        if ($order->status !== 'pending') {
            return back()->with('error', 'PO yang sudah diproses tidak bisa diedit.');
        }

        $this->normalizeItemPrices($request);

        $validated = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'order_date'  => 'required|date',
            'items'       => 'required|array|min:1',
            'items.*.product_id'       => 'required|exists:products,id',
            'items.*.product_unit_id'  => 'required|exists:product_units,id',
            'items.*.quantity_ordered' => 'required|numeric|min:0.01',
            'items.*.buy_price'        => 'required|numeric|min:0',
        ]);

        if (! $this->itemsBelongToSupplier((int) $validated['supplier_id'], $validated['items'])) {
            return back()
                ->withErrors(['items' => 'Daftar barang hanya boleh berisi barang dari supplier yang dipilih.'])
                ->withInput();
        }

        if (! $this->unitsBelongToProducts($validated['items'])) {
            return back()
                ->withErrors(['items' => 'Satuan beli harus sesuai dengan barang yang dipilih.'])
                ->withInput();
        }

        DB::transaction(function () use ($validated, $order) {
            $order->update([
                'supplier_id' => $validated['supplier_id'],
                'order_date'  => $validated['order_date'],
            ]);

            $order->items()->delete();

            foreach ($validated['items'] as $item) {
                if (empty($item['product_id'])) continue;
                PurchaseOrderItem::create([
                    'purchase_order_id' => $order->id,
                    'product_id'        => $item['product_id'],
                    'product_unit_id'   => $item['product_unit_id'],
                    'quantity_ordered'  => $item['quantity_ordered'],
                    'quantity_received' => 0,
                    'buy_price'         => $item['buy_price'],
                ]);

                DB::table('product_units')
                    ->where('id', $item['product_unit_id'])
                    ->where('product_id', $item['product_id'])
                    ->update(['buy_price' => $item['buy_price']]);
            }
        });

        return redirect()->route('purchase.orders.show', $order)
                         ->with('success', 'Purchase Order berhasil diperbarui.');
    }

    public function destroy(PurchaseOrder $order)
    {
        if ($order->status !== 'pending') {
            return back()->with('error', 'Hanya PO berstatus pending yang bisa dihapus.');
        }
        $order->delete();
        return redirect()->route('purchase.orders.index')
                         ->with('success', 'Purchase Order berhasil dihapus.');
    }

    public function generatePdf(PurchaseOrder $order)
    {
        $order->load(['supplier', 'createdBy', 'items.product.buyUnit', 'items.productUnit.unit']);
        $pdf = Pdf::loadView('purchase.orders.pdf', compact('order'))
                  ->setPaper('a4', 'portrait');
        return $pdf->stream("PO-{$order->po_number}.pdf");
    }

    public function productsBySupplier(Supplier $supplier)
    {
        return response()->json($supplier->products()
            ->with(['buyUnit', 'productUnits.unit', 'defaultPurchaseUnit.unit'])
            ->orderBy('name')
            ->get()
            ->map(fn ($product) => [
                'id' => $product->id,
                'name' => $product->name,
                'unit' => $product->defaultPurchaseUnit?->unit?->name ?? $product->buyUnit->name,
                'units' => $product->productUnits
                    ->sortByDesc('conversion_to_base')
                    ->map(fn ($productUnit) => [
                        'id' => $productUnit->id,
                        'name' => $productUnit->unit->name,
                        'buy_price' => (float) $productUnit->buy_price,
                        'is_default_purchase' => $productUnit->is_default_purchase,
                    ])
                    ->values()
                    ->all(),
                'price' => (float) $product->pivot->buy_price,
            ])
            ->values());
    }

    private function supplierProductsJson(): array
    {
        return Supplier::with(['products.buyUnit', 'products.productUnits.unit', 'products.defaultPurchaseUnit.unit'])
            ->get()
            ->mapWithKeys(fn ($supplier) => [
                $supplier->id => $supplier->products
                    ->sortBy('name')
                    ->map(fn ($product) => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'unit' => $product->defaultPurchaseUnit?->unit?->name ?? $product->buyUnit->name,
                        'price' => (float) ($product->defaultPurchaseUnit?->buy_price ?? $product->pivot->buy_price),
                        'units' => $product->productUnits
                            ->sortByDesc('conversion_to_base')
                            ->map(fn ($productUnit) => [
                                'id' => $productUnit->id,
                                'name' => $productUnit->unit->name,
                                'buy_price' => (float) $productUnit->buy_price,
                                'is_default_purchase' => $productUnit->is_default_purchase,
                            ])
                            ->values()
                            ->all(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    private function itemsBelongToSupplier(int $supplierId, array $items): bool
    {
        $productIds = collect($items)
            ->pluck('product_id')
            ->filter()
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return false;
        }

        $matchedCount = DB::table('product_supplier')
            ->where('supplier_id', $supplierId)
            ->whereIn('product_id', $productIds)
            ->count();

        return $matchedCount === $productIds->count();
    }

    private function unitsBelongToProducts(array $items): bool
    {
        $pairs = collect($items)
            ->map(fn ($item) => [
                'product_id' => (int) $item['product_id'],
                'product_unit_id' => (int) $item['product_unit_id'],
            ]);

        foreach ($pairs as $pair) {
            $exists = DB::table('product_units')
                ->where('id', $pair['product_unit_id'])
                ->where('product_id', $pair['product_id'])
                ->exists();

            if (! $exists) {
                return false;
            }
        }

        return true;
    }

    private function normalizeItemPrices(Request $request): void
    {
        $items = $request->input('items', []);

        foreach ($items as $index => $item) {
            if (array_key_exists('buy_price', $item)) {
                $items[$index]['buy_price'] = $this->normalizeCurrency($item['buy_price']);
            }
        }

        $request->merge(['items' => $items]);
    }

    private function normalizeCurrency(mixed $value): string
    {
        $value = preg_replace('/[^\d,\.]/', '', (string) $value);

        if (str_contains($value, ',') && str_contains($value, '.')) {
            return strrpos($value, ',') > strrpos($value, '.')
                ? str_replace(',', '.', str_replace('.', '', $value))
                : str_replace(',', '', $value);
        }

        if (preg_match('/^\d+\.\d{1,2}$/', $value)) {
            return $value;
        }

        if (preg_match('/^\d+,\d{1,2}$/', $value)) {
            return str_replace(',', '.', $value);
        }

        return str_replace(['.', ','], '', $value);
    }
}
