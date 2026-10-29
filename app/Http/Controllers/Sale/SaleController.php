<?php

namespace App\Http\Controllers\Sale;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemSource;
use App\Services\StockService;
use App\Services\HeldSaleService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function __construct(protected StockService $stockService, protected HeldSaleService $heldSaleService) {}

    public function index(Request $request)
    {
        ['from'=>$from, 'to'=>$to] = \App\Support\MonthlyDateRange::fromRequest($request);
        $query = Sale::with(['createdBy']);

        if (auth()->user()->isKasir()) {
            $query->where('created_by', auth()->id());
        }

        if ($from) {
            $query->whereDate('sale_date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('sale_date', '<=', $to);
        }
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $sales = $query->latest()->latest('id')->paginate(20)->withQueryString()->appends(['from'=>$from ?? '', 'to'=>$to ?? '']);
        $totalAmount = (clone $query)->paid()->sum('total_amount');

        return view('sale.index', compact('sales', 'totalAmount', 'from', 'to'));
    }

    public function create()
    {
        $locations = Location::orderBy('name')->get();
        $products = Product::with(['buyUnit', 'retailUnit', 'stocks.location', 'stockBatches', 'category', 'productUnits.unit', 'defaultPurchaseUnit'])
            ->active()
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
            'sale_status' => 'required|in:paid,pending',
            'draft_id' => 'nullable|uuid',
            'draft_version' => 'required_with:draft_id|nullable|integer|min:0',
            'transaction_confirmed' => 'accepted',
            'sale_date' => 'required|date_format:Y-m-d|before_or_equal:'.now('Asia/Jakarta')->toDateString(),
            'payment_method' => 'required|in:tunai,transfer',
            'amount_paid' => 'required|numeric|min:0',
            'customer_name' => 'nullable|string|max:150',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id,is_active,1',
            'items.*.product_unit_id' => 'required|exists:product_units,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.sources' => 'required|array|min:1',
            'items.*.sources.*.location_id' => 'required|exists:locations,id',
            'items.*.sources.*.quantity' => 'required|numeric|min:0',
        ], [
            'sale_date.before_or_equal' => 'Tanggal penjualan tidak boleh melewati hari ini (WIB).',
            'sale_date.date_format' => 'Tanggal penjualan harus berupa tanggal yang valid.',
            'transaction_confirmed.accepted' => 'Centang konfirmasi sebelum menyimpan transaksi.',
        ]);

        $pending = $request->input('sale_status') === 'pending';
        $jakartaNow = now('Asia/Jakarta');
        $holdUntil = $jakartaNow->copy()->setTime(18, 0);
        if ($pending && $jakartaNow->greaterThanOrEqualTo($holdUntil)) {
            return back()->with('error', 'Transaksi menunggu pembayaran hanya dapat disimpan sebelum pukul 18.00 WIB.')->withInput();
        }
        if ($pending && (float) $request->amount_paid !== 0.0) {
            return back()->with('error', 'Transaksi menunggu pembayaran belum menerima pembayaran. Isi jumlah bayar 0.')->withInput();
        }
        $requestedTotal = collect($request->items)->sum(fn ($item) => $item['quantity'] * $item['unit_price']);
        if (! $pending && (float) $request->amount_paid < $requestedTotal) {
            return back()->with('error', 'Jumlah bayar harus mencukupi total transaksi. Pilih Menunggu Pembayaran bila belum dibayar.')->withInput();
        }

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
            $sale = DB::transaction(function () use ($request, $pending, $holdUntil, $jakartaNow) {
                app(\App\Services\MovingAverageCostService::class)->lock();
                app(\App\Services\SaleDraftService::class)->consume($request);
                $totalAmount = collect($request->items)->sum(fn ($i) => $i['quantity'] * $i['unit_price']
                );

                $customerName = trim((string) $request->input('customer_name'));

                $sale = Sale::create([
                    'sale_number' => Sale::generateSaleNumber($jakartaNow),
                    'customer_name' => $customerName !== '' ? $customerName : null,
                    'sale_date' => $request->sale_date,
                    'sale_time' => $jakartaNow->format('H:i:s'),
                    'total_amount' => $totalAmount,
                    'payment_method' => $request->payment_method,
                    'amount_paid' => $request->amount_paid,
                    'change_amount' => $pending ? 0 : max(0, $request->amount_paid - $totalAmount),
                    'status' => $pending ? 'pending' : 'paid',
                    'hold_until' => $pending ? $holdUntil->format('Y-m-d H:i:s') : null,
                    'created_by' => auth()->id(),
                ]);

                foreach ($request->items as $itemData) {
                    $product = Product::with(['defaultPurchaseUnit', 'productUnits'])->find($itemData['product_id']);
                    $productUnit = $product->productUnits()->lockForUpdate()->find((int) $itemData['product_unit_id']);

                    if (! $productUnit) {
                        throw new \Exception('Satuan penjualan tidak sesuai dengan produk.');
                    }

                    $purchaseUnit = $product->defaultPurchaseUnit;
                    $baseCost = $purchaseUnit && (float) $purchaseUnit->conversion_to_base > 0
                        ? (float) $purchaseUnit->buy_price / (float) $purchaseUnit->conversion_to_base
                        : (float) $product->buy_price;
                    $costPrice = $baseCost * (float) $productUnit->conversion_to_base;
                    $costMethod = app(\App\Services\MovingAverageCostService::class)->prepare($product) ? 'reserved' : 'legacy_master';
                    if ($costMethod === 'reserved') $costPrice = 0;
                    $legacySaleType = (float) $productUnit->conversion_to_base > 1 ? 'grosir' : 'ecer';

                    $saleItem = SaleItem::create([
                        'sale_id' => $sale->id,
                        'product_id' => $product->id,
                        'product_unit_id' => $productUnit->id,
                        'sale_type' => $legacySaleType,
                        'quantity' => $itemData['quantity'],
                        'unit_price' => $itemData['unit_price'],
                        'cost_price' => $costPrice,
                        'cost_method' => $costMethod,
                        'subtotal' => $itemData['quantity'] * $itemData['unit_price'],
                        'cost_subtotal' => $itemData['quantity'] * $costPrice,
                    ]);

                    foreach ($itemData['sources'] as $source) {
                        if (empty($source['quantity']) || $source['quantity'] <= 0) {
                            continue;
                        }

                        $location = Location::find($source['location_id']);

                        $batchDeductions = $this->stockService->deductStock(
                            $product,
                            $location,
                            $source['quantity'],
                            (string) $productUnit->id,
                            $sale,
                            $sale->sale_date,
                            $sale->sale_number
                        );

                        if ($product->is_expirable && $batchDeductions) {
                            foreach ($batchDeductions as $batchDeduction) {
                                SaleItemSource::create([
                                    'sale_item_id' => $saleItem->id,
                                    'location_id' => $source['location_id'],
                                    'stock_batch_id' => $batchDeduction['stock_batch_id'],
                                    'base_quantity' => $batchDeduction['quantity'],
                                    'quantity' => $this->stockService->fromBaseQuantity(
                                        (float) $batchDeduction['quantity'],
                                        $productUnit
                                    ),
                                ]);
                            }
                        } else {
                            SaleItemSource::create([
                                'sale_item_id' => $saleItem->id,
                                'location_id' => $source['location_id'],
                                'quantity' => $source['quantity'],
                                'base_quantity' => $this->stockService->toBaseQuantity($product, (float) $source['quantity'], $productUnit),
                            ]);
                        }
                    }
                }

                if (!$pending) app(\App\Services\MovingAverageCostService::class)->finalizeSale($sale);
                return $sale;
            });
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('sale.sales.index')
            ->with('completed_sale_draft', $request->input('draft_id'))
            ->with('success', $pending ? 'Transaksi menunggu pembayaran berhasil disimpan.' : 'Transaksi penjualan berhasil disimpan.');
    }

    public function complete(Request $request, Sale $sale)
    {
        $this->ensureSaleIsAccessible($sale);
        $rawAmount = trim((string) $request->input('amount_paid'));
        $request->merge(['amount_paid' => $rawAmount === '' ? null : $this->normalizeCurrency($rawAmount)]);
        $request->validate([
            'payment_method' => 'required|in:tunai,transfer',
            'amount_paid' => 'required|numeric|min:1',
            'payment_confirmed' => 'accepted',
        ], [
            'amount_paid.required' => 'Jumlah bayar wajib diisi.',
            'amount_paid.min' => 'Jumlah bayar harus lebih dari 0.',
            'payment_confirmed.accepted' => 'Centang konfirmasi bahwa pembayaran sudah diterima.',
        ]);

        $result = DB::transaction(function () use ($request, $sale) {
            app(\App\Services\MovingAverageCostService::class)->lock();
            $locked = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                return 'closed';
            }
            if ($locked->hold_until->format('Y-m-d H:i:s') <= now('Asia/Jakarta')->format('Y-m-d H:i:s')) {
                $this->heldSaleService->cancel($locked, true);
                return 'late';
            }
            if ((float) $request->amount_paid < (float) $locked->total_amount) {
                return 'short';
            }
            if ($locked->sale_date->toDateString() > now('Asia/Jakarta')->toDateString()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'sale_date' => 'Tanggal penjualan tidak boleh melewati hari ini (WIB). Ubah tanggal transaksi sebelum menyelesaikan pembayaran.',
                ]);
            }
            app(\App\Services\MovingAverageCostService::class)->finalizeSale($locked);
            $locked->update([
                'status' => 'paid',
                'payment_method' => $request->payment_method,
                'amount_paid' => $request->amount_paid,
                'change_amount' => (float) $request->amount_paid - (float) $locked->total_amount,
                'hold_until' => null,
            ]);

            return 'paid';
        });

        if ($result !== 'paid') {
            $message = match ($result) {
                'late' => 'Batas pukul 18.00 WIB sudah lewat. Transaksi dibatalkan otomatis dan stok dikembalikan.',
                'short' => 'Jumlah bayar harus mencukupi total transaksi.',
                default => 'Transaksi ini sudah tidak menunggu pembayaran.',
            };
            return back()->with('error', $message);
        }

        return redirect()->route('sale.sales.show', $sale)->with('success', 'Pembayaran berhasil diselesaikan.');
    }

    public function cancel(Sale $sale)
    {
        $this->ensureSaleIsAccessible($sale);
        $overdue = $sale->hold_until && $sale->hold_until->format('Y-m-d H:i:s') <= now('Asia/Jakarta')->format('Y-m-d H:i:s');
        if (! $this->heldSaleService->cancel($sale, $overdue)) {
            return back()->with('error', 'Transaksi ini sudah tidak menunggu pembayaran.');
        }

        return redirect()->route('sale.sales.show', $sale)->with('success', 'Transaksi dibatalkan dan stok dikembalikan.');
    }

    public function show(Sale $sale)
    {
        $this->ensureSaleIsAccessible($sale);
        $sale->load(['createdBy', 'items.product.buyUnit', 'items.product.retailUnit', 'items.productUnit.unit', 'items.sources.location', 'items.sources.stockBatch']);

        return view('sale.show', compact('sale'));
    }

    public function printNota(Request $request, Sale $sale)
    {
        $this->ensureSaleIsAccessible($sale);
        abort_unless($sale->status === 'paid', 404);
        $sale->load(['createdBy', 'items.product.buyUnit', 'items.product.retailUnit', 'items.productUnit.unit', 'items.sources.location', 'items.sources.stockBatch']);

        if ($request->boolean('popup')) {
            return view('sale.nota', compact('sale'))->with('previewMode', true);
        }

        $pdf = Pdf::loadView('sale.nota', compact('sale'))
            ->setPaper([0, 0, 226.77, 600], 'portrait');

        $filename = 'Nota-'.str_replace(['/', '\\'], '-', $sale->sale_number).'.pdf';

        return $pdf->stream($filename);
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
        $this->ensureSaleIsAccessible($sale);

        if ($sale->status !== 'pending') {
            return back()->with('error', 'Hanya transaksi yang masih menunggu pembayaran yang dapat diedit.');
        }
        if ($sale->hold_until->format('Y-m-d H:i:s') <= now('Asia/Jakarta')->format('Y-m-d H:i:s')) {
            $this->heldSaleService->cancel($sale, true);

            return redirect()->route('sale.sales.index')
                ->with('error', 'Batas pukul 18.00 WIB sudah lewat. Transaksi dibatalkan otomatis dan stok dikembalikan.');
        }

        $sale->load(['items.sources']);
        $initialItems = $sale->items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'product_unit_id' => $item->product_unit_id,
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'sources' => $item->sources
                ->groupBy('location_id')
                ->map(fn ($sources, $locationId) => [
                    'location_id' => (int) $locationId,
                    'quantity' => $sources->sum(fn ($source) => (float) $source->quantity),
                ])->values()->all(),
        ])->values()->all();

        $formData = $this->create()->getData();

        return view('sale.form', array_merge($formData, compact('sale', 'initialItems')));
    }

    public function update(Request $request, Sale $sale)
    {
        $this->ensureSaleIsAccessible($sale);
        $this->normalizeCurrencyInputs($request);
        $settlePayment = $request->input('sale_status', 'pending') === 'paid';
        $request->validate([
            'sale_status' => 'sometimes|required|in:pending,paid',
            'amount_paid' => $settlePayment ? 'required|numeric|min:1' : 'nullable|numeric|min:0',
            'draft_id' => 'nullable|uuid',
            'draft_version' => 'required_with:draft_id|nullable|integer|min:0',
            'transaction_confirmed' => 'accepted',
            'sale_date' => 'required|date_format:Y-m-d|before_or_equal:'.now('Asia/Jakarta')->toDateString(),
            'payment_method' => 'required|in:tunai,transfer',
            'customer_name' => 'nullable|string|max:150',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id,is_active,1',
            'items.*.product_unit_id' => 'required|exists:product_units,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.sources' => 'required|array|min:1',
            'items.*.sources.*.location_id' => 'required|exists:locations,id',
            'items.*.sources.*.quantity' => 'required|numeric|min:0',
        ], [
            'sale_date.before_or_equal' => 'Tanggal penjualan tidak boleh melewati hari ini (WIB).',
            'sale_date.date_format' => 'Tanggal penjualan harus berupa tanggal yang valid.',
            'transaction_confirmed.accepted' => 'Centang konfirmasi sebelum menyimpan perubahan.',
            'amount_paid.min' => 'Jumlah bayar harus lebih dari 0.',
        ]);

        $totalAmount = collect($request->items)->sum(fn ($item) => $item['quantity'] * $item['unit_price']);
        if ($settlePayment && (float) $request->amount_paid < $totalAmount) {
            return back()->withErrors(['amount_paid' => 'Jumlah bayar harus mencukupi total transaksi setelah perubahan.'])->withInput();
        }

        foreach ($request->items as $item) {
            $allocated = collect($item['sources'] ?? [])->sum(fn ($source) => (float) ($source['quantity'] ?? 0));
            if (abs($allocated - (float) $item['quantity']) > 0.0001) {
                $productName = Product::find($item['product_id'])?->name ?? 'barang';

                return back()->with('error', "Total alokasi lokasi untuk {$productName} harus sama dengan jumlah yang dijual.")->withInput();
            }
        }

        try {
            $result = DB::transaction(function () use ($request, $sale, $settlePayment, $totalAmount) {
                app(\App\Services\MovingAverageCostService::class)->lock();
                $locked = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'pending') {
                    return 'closed';
                }
                if ($locked->hold_until->format('Y-m-d H:i:s') <= now('Asia/Jakarta')->format('Y-m-d H:i:s')) {
                    $this->heldSaleService->cancel($locked, true);
                    return 'late';
                }

                $this->stockService->restoreHeldSale($locked, 'sale_edit_restore');
                app(\App\Services\SaleDraftService::class)->consume($request, $sale->id);
                $locked->items()->delete();

                $customerName = trim((string) $request->input('customer_name'));
                $locked->update([
                    'customer_name' => $customerName !== '' ? $customerName : null,
                    'sale_date' => $request->sale_date,
                    'total_amount' => $totalAmount,
                    'payment_method' => $request->payment_method,
                    'amount_paid' => 0,
                    'change_amount' => 0,
                ]);

                $this->createSaleItems($locked, $request->items);

                if ($settlePayment) {
                    app(\App\Services\MovingAverageCostService::class)->finalizeSale($locked);
                    $locked->update([
                        'status' => 'paid',
                        'amount_paid' => $request->amount_paid,
                        'change_amount' => (float) $request->amount_paid - $totalAmount,
                        'hold_until' => null,
                    ]);

                    return 'paid';
                }

                return 'updated';
            });
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        if (! in_array($result, ['updated', 'paid'], true)) {
            $message = $result === 'late'
                ? 'Batas pukul 18.00 WIB sudah lewat. Transaksi dibatalkan otomatis dan stok dikembalikan.'
                : 'Transaksi ini sudah tidak menunggu pembayaran.';

            return redirect()->route('sale.sales.index')->with('error', $message);
        }

        return redirect()->route('sale.sales.show', $sale)
            ->with('completed_sale_draft', $request->input('draft_id'))
            ->with('success', $result === 'paid' ? 'Perubahan transaksi disimpan dan pembayaran berhasil diselesaikan.' : 'Transaksi menunggu pembayaran berhasil diperbarui.');
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

    private function createSaleItems(Sale $sale, array $items): void
    {
        foreach ($items as $itemData) {
            $product = Product::with(['defaultPurchaseUnit', 'productUnits'])->findOrFail($itemData['product_id']);
            $productUnit = $product->productUnits()->lockForUpdate()->find((int) $itemData['product_unit_id']);
            if (! $productUnit) {
                throw new \Exception('Satuan penjualan tidak sesuai dengan produk.');
            }

            $purchaseUnit = $product->defaultPurchaseUnit;
            $baseCost = $purchaseUnit && (float) $purchaseUnit->conversion_to_base > 0
                ? (float) $purchaseUnit->buy_price / (float) $purchaseUnit->conversion_to_base
                : (float) $product->buy_price;
            $costPrice = $baseCost * (float) $productUnit->conversion_to_base;
            $costMethod = app(\App\Services\MovingAverageCostService::class)->prepare($product) ? 'reserved' : 'legacy_master';
            if ($costMethod === 'reserved') $costPrice = 0;

            $saleItem = SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'product_unit_id' => $productUnit->id,
                'sale_type' => (float) $productUnit->conversion_to_base > 1 ? 'grosir' : 'ecer',
                'quantity' => $itemData['quantity'],
                'unit_price' => $itemData['unit_price'],
                'cost_price' => $costPrice,
                'cost_method' => $costMethod,
                'subtotal' => $itemData['quantity'] * $itemData['unit_price'],
                'cost_subtotal' => $itemData['quantity'] * $costPrice,
            ]);

            foreach ($itemData['sources'] as $source) {
                if (empty($source['quantity']) || $source['quantity'] <= 0) {
                    continue;
                }
                $location = Location::findOrFail($source['location_id']);
                $deductions = $this->stockService->deductStock(
                    $product, $location, $source['quantity'], (string) $productUnit->id,
                    $sale, $sale->sale_date, $sale->sale_number
                );

                if ($product->is_expirable && $deductions) {
                    foreach ($deductions as $deduction) {
                        SaleItemSource::create([
                            'sale_item_id' => $saleItem->id,
                            'location_id' => $source['location_id'],
                            'stock_batch_id' => $deduction['stock_batch_id'],
                            'base_quantity' => $deduction['quantity'],
                            'quantity' => $this->stockService->fromBaseQuantity((float) $deduction['quantity'], $productUnit),
                        ]);
                    }
                } else {
                    SaleItemSource::create([
                        'sale_item_id' => $saleItem->id,
                        'location_id' => $source['location_id'],
                        'quantity' => $source['quantity'],
                        'base_quantity' => $this->stockService->toBaseQuantity($product, (float) $source['quantity'], $productUnit),
                    ]);
                }
            }
        }
    }
}
