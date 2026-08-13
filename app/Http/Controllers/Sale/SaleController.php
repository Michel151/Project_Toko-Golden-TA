<?php

namespace App\Http\Controllers\Sale;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemSource;
use App\Services\StockService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function __construct(protected StockService $stockService) {}

    public function index(Request $request)
    {
        $query = Sale::with(['createdBy']);

        if (auth()->user()->isKasir()) {
            $query->where('created_by', auth()->id());
        }

        if ($request->filled('from')) {
            $query->whereDate('sale_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('sale_date', '<=', $request->to);
        }
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        $sales = $query->latest()->paginate(20)->withQueryString();
        $totalAmount = $query->sum('total_amount');

        return view('sale.index', compact('sales', 'totalAmount'));
    }

    public function create()
    {
        $locations = Location::orderBy('name')->get();
        $products = Product::with(['buyUnit', 'retailUnit', 'stocks.location', 'stockBatches', 'category', 'productUnits.unit', 'defaultPurchaseUnit'])
            ->orderBy('name')->get();

        $productsJson = $products->map(function ($p) {
            return [
                'id' => $p->id,
                'name' => $p->name,
                'barcode' => $p->barcode,
                'category_id' => $p->category_id,
                'category_name' => optional($p->category)->name ?? 'Tanpa Kategori',
                'is_expirable' => (bool) $p->is_expirable,
                'can_retail' => $p->can_retail,
                'buy_unit_name' => $p->buyUnit->name,
                'retail_unit_name' => optional($p->retailUnit)->name,
                'sell_price_wholesale' => (float) $p->sell_price_wholesale,
                'sell_price_retail' => (float) $p->sell_price_retail,
                'conversion_factor' => (float) $p->conversion_factor,
                'product_units' => $p->productUnits
                    ->sortByDesc(fn ($unit) => (float) $unit->conversion_to_base)
                    ->map(fn ($unit) => [
                        'id' => $unit->id,
                        'unit_id' => $unit->unit_id,
                        'unit_name' => $unit->unit->name,
                        'conversion_to_base' => (float) $unit->conversion_to_base,
                        'buy_price' => (float) $unit->buy_price,
                        'sell_price' => (float) $unit->sell_price,
                        'is_base_unit' => $unit->is_base_unit,
                        'is_default_purchase' => $unit->is_default_purchase,
                        'is_default_sale' => $unit->is_default_sale,
                    ])
                    ->values()
                    ->toArray(),
                'stocks' => $p->stocks->map(function ($s) use ($p) {
                    $quantity = $p->is_expirable
                        ? $p->stockBatches
                            ->where('location_id', $s->location_id)
                            ->filter(fn ($batch) => (float) $batch->quantity_remaining > 0 && (! $batch->expired_date || $batch->expired_date->gte(today())))
                            ->sum(fn ($batch) => (float) $batch->quantity_remaining)
                        : (float) $s->quantity;

                    return [
                        'location_id' => $s->location_id,
                        'location_name' => $s->location->name,
                        'quantity' => (float) $quantity,
                    ];
                })->values()->toArray(),
            ];
        })->values()->toArray();

        $locationsJson = $locations->map(function ($l) {
            return ['id' => $l->id, 'name' => $l->name];
        })->values()->toArray();

        return view('sale.form', compact('products', 'productsJson', 'locationsJson'));
    }

    public function store(Request $request)
    {
        $this->normalizeCurrencyInputs($request);

        $request->validate([
            'sale_date' => 'required|date',
            'payment_method' => 'required|in:tunai,transfer',
            'amount_paid' => 'required|numeric|min:0',
            'customer_name' => 'nullable|string|max:150',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.product_unit_id' => 'required|exists:product_units,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.sources' => 'required|array|min:1',
            'items.*.sources.*.location_id' => 'required|exists:locations,id',
            'items.*.sources.*.quantity' => 'required|numeric|min:0',
        ]);

        foreach ($request->items as $item) {
            $allocatedQuantity = collect($item['sources'] ?? [])->sum(fn ($source) => (float) ($source['quantity'] ?? 0));
            if (abs($allocatedQuantity - (float) $item['quantity']) > 0.0001) {
                $productName = Product::find($item['product_id'])?->name ?? 'barang';

                return back()
                    ->with('error', "Total alokasi lokasi untuk {$productName} harus sama dengan jumlah yang dijual.")
                    ->withInput();
            }
        }

        try {
            $sale = DB::transaction(function () use ($request) {
                $totalAmount = collect($request->items)->sum(fn ($i) => $i['quantity'] * $i['unit_price']
                );

                $sale = Sale::create([
                    'sale_number' => Sale::generateSaleNumber(),
                    'customer_name' => $request->customer_name,
                    'sale_date' => $request->sale_date,
                    'total_amount' => $totalAmount,
                    'payment_method' => $request->payment_method,
                    'amount_paid' => $request->amount_paid,
                    'change_amount' => max(0, $request->amount_paid - $totalAmount),
                    'created_by' => auth()->id(),
                ]);

                foreach ($request->items as $itemData) {
                    $product = Product::with(['defaultPurchaseUnit', 'productUnits'])->find($itemData['product_id']);
                    $productUnit = $product->productUnits->firstWhere('id', (int) $itemData['product_unit_id']);

                    if (! $productUnit) {
                        throw new \Exception('Satuan penjualan tidak sesuai dengan produk.');
                    }

                    $purchaseUnit = $product->defaultPurchaseUnit;
                    $baseCost = $purchaseUnit && (float) $purchaseUnit->conversion_to_base > 0
                        ? (float) $purchaseUnit->buy_price / (float) $purchaseUnit->conversion_to_base
                        : (float) $product->buy_price;
                    $costPrice = $baseCost * (float) $productUnit->conversion_to_base;
                    $legacySaleType = (float) $productUnit->conversion_to_base > 1 ? 'grosir' : 'ecer';

                    $saleItem = SaleItem::create([
                        'sale_id' => $sale->id,
                        'product_id' => $product->id,
                        'product_unit_id' => $productUnit->id,
                        'sale_type' => $legacySaleType,
                        'quantity' => $itemData['quantity'],
                        'unit_price' => $itemData['unit_price'],
                        'cost_price' => $costPrice,
                        'subtotal' => $itemData['quantity'] * $itemData['unit_price'],
                        'cost_subtotal' => $itemData['quantity'] * $costPrice,
                    ]);

                    foreach ($itemData['sources'] as $source) {
                        if (empty($source['quantity']) || $source['quantity'] <= 0) {
                            continue;
                        }

                        $location = Location::find($source['location_id']);

                        SaleItemSource::create([
                            'sale_item_id' => $saleItem->id,
                            'location_id' => $source['location_id'],
                            'quantity' => $source['quantity'],
                        ]);

                        $this->stockService->deductStock(
                            $product,
                            $location,
                            $source['quantity'],
                            (string) $productUnit->id,
                            $sale,
                            $sale->sale_date,
                            $sale->sale_number
                        );
                    }
                }

                return $sale;
            });
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('sale.sales.index')
            ->with('success', 'Transaksi penjualan berhasil disimpan.');
    }

    public function show(Sale $sale)
    {
        $this->ensureSaleIsAccessible($sale);
        $sale->load(['createdBy', 'items.product.buyUnit', 'items.product.retailUnit', 'items.productUnit.unit', 'items.sources.location']);

        return view('sale.show', compact('sale'));
    }

    public function printNota(Request $request, Sale $sale)
    {
        $this->ensureSaleIsAccessible($sale);
        $sale->load(['createdBy', 'items.product.buyUnit', 'items.product.retailUnit', 'items.productUnit.unit', 'items.sources.location']);

        if ($request->boolean('popup')) {
            return view('sale.nota', compact('sale'))->with('previewMode', true);
        }

        $pdf = Pdf::loadView('sale.nota', compact('sale'))
            ->setPaper([0, 0, 226.77, 600], 'portrait');

        return $pdf->stream("Nota-{$sale->sale_number}.pdf");
    }

    public function getProductData(Product $product)
    {
        $product->load(['buyUnit', 'retailUnit', 'stocks.location', 'stockBatches', 'productUnits.unit']);

        return response()->json([
            'id' => $product->id,
            'name' => $product->name,
            'is_expirable' => (bool) $product->is_expirable,
            'can_retail' => $product->can_retail,
            'buy_unit_name' => $product->buyUnit->name,
            'retail_unit_name' => optional($product->retailUnit)->name,
            'conversion_factor' => $product->conversion_factor,
            'sell_price_wholesale' => $product->sell_price_wholesale,
            'sell_price_retail' => $product->sell_price_retail,
            'product_units' => $product->productUnits->map(fn ($unit) => [
                'id' => $unit->id,
                'unit_name' => $unit->unit->name,
                'conversion_to_base' => $unit->conversion_to_base,
                'sell_price' => $unit->sell_price,
            ]),
            'stocks' => $product->stocks->map(function ($s) use ($product) {
                $quantity = $product->is_expirable
                    ? $product->stockBatches
                        ->where('location_id', $s->location_id)
                        ->filter(fn ($batch) => (float) $batch->quantity_remaining > 0 && (! $batch->expired_date || $batch->expired_date->gte(today())))
                        ->sum(fn ($batch) => (float) $batch->quantity_remaining)
                    : (float) $s->quantity;

                return [
                    'location_id' => $s->location_id,
                    'location_name' => $s->location->name,
                    'quantity' => $quantity,
                ];
            }),
        ]);
    }

    public function edit(Sale $sale)
    {
        return back()->with('error', 'Transaksi penjualan tidak bisa diedit.');
    }

    public function destroy(Sale $sale)
    {
        return back()->with('error', 'Transaksi penjualan tidak bisa dihapus.');
    }

    private function ensureSaleIsAccessible(Sale $sale): void
    {
        if (auth()->user()->isKasir() && (int) $sale->created_by !== (int) auth()->id()) {
            abort(403, 'Anda tidak memiliki akses ke transaksi kasir lain.');
        }
    }

    private function normalizeCurrencyInputs(Request $request): void
    {
        $items = $request->input('items', []);

        foreach ($items as $index => $item) {
            if (array_key_exists('unit_price', $item)) {
                $items[$index]['unit_price'] = $this->normalizeCurrency($item['unit_price']);
            }
        }

        $request->merge([
            'amount_paid' => $this->normalizeCurrency($request->input('amount_paid', 0)),
            'items' => $items,
        ]);
    }

    private function normalizeCurrency(mixed $value): string
    {
        return preg_replace('/[^\d]/', '', (string) $value) ?: '0';
    }
}
