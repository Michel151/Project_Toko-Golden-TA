<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductLocation;
use App\Models\ProductUnit;
use App\Models\Stock;
use App\Models\Unit;
use App\Support\DeletionConstraint;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with(['category', 'buyUnit', 'retailUnit', 'stocks', 'productUnits.unit']);

        if ($request->filled('search')) {
            $query->where(function ($query) use ($request) {
                $query->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('barcode', 'like', '%' . $request->search . '%');
            });
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        $products   = $query->orderBy('name')->orderBy('id')->paginate(20)->withQueryString();
        $categories = Category::orderBy('name')->get();

        return view('master.products.index', compact('products', 'categories'));
    }

    public function create()
    {
        $categories = Category::orderBy('name')->get();
        $units      = Unit::orderBy('name')->get();
        $locations  = Location::orderBy('name')->get();
        return view('master.products.form', compact('categories', 'units', 'locations'));
    }

    public function store(Request $request)
    {
        $this->normalizePrices($request);

        $request->validate($this->rules());
        $this->validateProductUnitSelection($request);
        $this->applyCalculatedConversions($request);
        $this->applyCalculatedBuyPrices($request);
        $this->applyMinimumStockConversions($request);

        DB::transaction(function () use ($request) {
            $product = Product::create([
                'name'                 => $request->name,
                'barcode'              => $request->barcode,
                'is_expirable'         => $request->boolean('is_expirable'),
                'is_active'            => $request->boolean('is_active', true),
                'category_id'         => $request->category_id,
                'buy_unit_id'         => $request->product_units[$request->default_purchase_unit_index]['unit_id'],
                'buy_price'           => $request->product_units[$request->default_purchase_unit_index]['buy_price'],
                'sell_price_wholesale'=> $request->product_units[$request->default_purchase_unit_index]['sell_price'],
                'can_retail'          => count($request->product_units) > 1,
                'retail_unit_id'      => $request->product_units[$request->base_unit_index]['unit_id'],
                'conversion_factor'   => $request->product_units[$request->default_purchase_unit_index]['conversion_to_base'],
                'sell_price_retail'   => $request->product_units[$request->base_unit_index]['sell_price'],
                'min_stock_total'     => $request->min_stock_total ?? 0,
            ]);

            $this->syncProductUnits($product, $request);

            if (!$product->barcode) {
                $product->updateQuietly(['barcode' => 'TG' . str_pad((string) $product->id, 10, '0', STR_PAD_LEFT)]);
            }

            $this->syncProductLocations($product, $request);
        });

        return redirect()->route('master.products.index')->with('success', 'Barang berhasil ditambahkan.');
    }

    public function show(Request $request, Product $product)
    {
        $product->load(['category', 'buyUnit', 'retailUnit', 'stocks.location', 'stockBatches.location', 'productLocations.location', 'productUnits.unit']);

        $movementQuery = $product->stockMovements()
            ->with(['location', 'createdBy'])
            ->when($request->filled('location_id'), fn ($query) => $query->where('location_id', $request->location_id))
            ->when($request->filled('movement_type'), fn ($query) => $query->where('movement_type', $request->movement_type))
            ->when($request->filled('direction'), function ($query) use ($request) {
                $request->direction === 'in'
                    ? $query->where('quantity', '>', 0)
                    : $query->where('quantity', '<', 0);
            })
            ->when($request->filled('from'), fn ($query) => $query->whereDate('movement_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('movement_date', '<=', $request->to));

        $totalMovementIn = (float) (clone $movementQuery)->where('quantity', '>', 0)->sum('quantity');
        $totalMovementOut = abs((float) (clone $movementQuery)->where('quantity', '<', 0)->sum('quantity'));
        $movementCount = (clone $movementQuery)->count();
        $movements = $movementQuery->latest('movement_date')->latest('id')->paginate(15)->withQueryString();
        $movementLocations = $product->productLocations->pluck('location')->filter()->sortBy('name');

        return view('master.products.show', compact(
            'product', 'movements', 'movementLocations',
            'totalMovementIn', 'totalMovementOut', 'movementCount'
        ));
    }

    public function edit(Product $product)
    {
        $categories      = Category::orderBy('name')->get();
        $units           = Unit::orderBy('name')->get();
        $locations       = Location::orderBy('name')->get();
        $product->load('productUnits.unit');
        $productLocations = $product->productLocations->keyBy('location_id');
        $expirySettingLocked = $product->isExpirySettingLocked();
        return view('master.products.form', compact('product', 'categories', 'units', 'locations', 'productLocations', 'expirySettingLocked'));
    }

    public function printLabel(Product $product)
    {
        $product->load(['category', 'buyUnit', 'retailUnit']);

        return view('master.products.label', compact('product'));
    }

    public function update(Request $request, Product $product)
    {
        $this->normalizePrices($request);

        $request->validate($this->rules($product->id));
        $this->validateProductUnitSelection($request);
        $this->applyCalculatedConversions($request);
        $this->applyCalculatedBuyPrices($request);
        $this->applyMinimumStockConversions($request);

        DB::transaction(function () use ($request, $product) {
            // Use the same gate/order as stock writers before checking current history.
            app(\App\Services\MovingAverageCostService::class)->lock();
            $product = Product::lockForUpdate()->findOrFail($product->id);
            if ($request->boolean('is_expirable') !== $product->is_expirable && $product->isExpirySettingLocked()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'is_expirable' => 'Pengaturan kedaluwarsa tidak dapat diubah karena barang sudah memiliki stok atau riwayat transaksi.',
                ]);
            }
            $this->validateHistoricalUnits($product, $request);
            $product->update([
                'name'                 => $request->name,
                'barcode'              => $request->barcode ?: $product->barcode,
                'is_expirable'         => $request->boolean('is_expirable'),
                'is_active'            => $request->boolean('is_active'),
                'category_id'         => $request->category_id,
                'buy_unit_id'         => $request->product_units[$request->default_purchase_unit_index]['unit_id'],
                'buy_price'           => $request->product_units[$request->default_purchase_unit_index]['buy_price'],
                'sell_price_wholesale'=> $request->product_units[$request->default_purchase_unit_index]['sell_price'],
                'can_retail'          => count($request->product_units) > 1,
                'retail_unit_id'      => $request->product_units[$request->base_unit_index]['unit_id'],
                'conversion_factor'   => $request->product_units[$request->default_purchase_unit_index]['conversion_to_base'],
                'sell_price_retail'   => $request->product_units[$request->base_unit_index]['sell_price'],
                'min_stock_total'     => $request->min_stock_total ?? 0,
            ]);

            $this->syncProductUnits($product, $request);

            $this->syncProductLocations($product, $request);
        });

        return redirect()->route('master.products.index')->with('success', 'Barang berhasil diperbarui.');
    }

    public function destroy(Product $product)
    {
        try {
            $result = DB::transaction(function () use ($product) {
                app(\App\Services\MovingAverageCostService::class)->lock();
                $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
                if ($locked->costBalance()->exists()) return 'cost';
                if ($locked->stocks()->where('quantity', '!=', 0)->exists()) return 'stock';
                if ($locked->hasTransactionHistory()) return 'history';
                $locked->delete();
                return 'deleted';
            }, 3);
        } catch (QueryException $exception) {
            if (!DeletionConstraint::isReferenced($exception, 'products')) throw $exception;
            $result = 'history';
        }

        if ($result === 'cost') {
            return back()->with('error', 'Barang memiliki riwayat penilaian persediaan. Nonaktifkan barang untuk mempertahankan riwayatnya.');
        }
        if ($result === 'stock') {
            return back()->with('error', 'Barang tidak bisa dihapus karena masih memiliki stok.');
        }
        if ($result === 'history') {
            return back()->with('error', 'Barang tidak dapat dihapus karena masih digunakan dalam riwayat transaksi atau batch stok. Nonaktifkan barang untuk mempertahankan riwayatnya.');
        }
        return redirect()->route('master.products.index')->with('success', 'Barang berhasil dihapus.');
    }

    private function rules(?int $ignoreId = null): array
    {
        return [
            'name'                 => 'required|string|max:200',
            'barcode'              => 'nullable|string|max:100|unique:products,barcode,' . ($ignoreId ?? 'NULL'),
            'is_expirable'         => 'nullable|boolean',
            'is_active'            => 'nullable|boolean',
            'category_id'         => 'required|exists:categories,id',
            'product_units'       => 'required|array|min:1',
            'product_units.*.unit_id' => 'required|exists:units,id|distinct',
            'product_units.*.contains_quantity' => 'required|numeric|min:0.0001',
            'product_units.*.contains_unit_index' => 'nullable|integer|min:0',
            'product_units.*.conversion_to_base' => 'nullable|numeric|min:0.0001',
            'product_units.*.buy_price' => 'nullable|numeric|min:0',
            'product_units.*.sell_price' => 'required|numeric|min:0',
            'base_unit_index' => 'required|integer|min:0',
            'default_purchase_unit_index' => 'required|integer|min:0',
            'default_sale_unit_index' => 'required|integer|min:0',
            'min_stock_total'     => 'nullable|numeric|min:0',
            'min_stock_total_unit_index' => 'nullable|integer|min:0',
            'active_locations'    => 'nullable|array',
            'active_locations.*'  => 'exists:locations,id',
            'locations'           => 'nullable|array',
            'locations.*'         => 'nullable|numeric|min:0',
            'location_units'      => 'nullable|array',
            'location_units.*'    => 'nullable|integer|min:0',
        ];
    }

    private function syncProductLocations(Product $product, Request $request): void
    {
        $activeLocationIds = collect($request->input('active_locations', []))
            ->map(fn ($locationId) => (int) $locationId)
            ->unique()
            ->values();

        $product->productLocations()
            ->whereNotIn('location_id', $activeLocationIds)
            ->delete();

        foreach ($activeLocationIds as $locationId) {
            ProductLocation::updateOrCreate(
                ['product_id' => $product->id, 'location_id' => $locationId],
                ['min_stock'  => $request->input("locations.{$locationId}", 0) ?? 0]
            );

            Stock::firstOrCreate(
                ['product_id' => $product->id, 'location_id' => $locationId],
                ['quantity'   => 0]
            );
        }
    }

    private function normalizePrices(Request $request): void
    {
        $request->merge([
            'product_units' => collect($request->input('product_units', []))
                ->map(function ($unit) {
                    $unit['buy_price'] = $this->normalizeCurrency($unit['buy_price'] ?? 0);
                    $unit['sell_price'] = $this->normalizeCurrency($unit['sell_price'] ?? 0);
                    $unit['contains_quantity'] = $unit['contains_quantity'] ?? 1;
                    return $unit;
                })
                ->values()
                ->all(),
        ]);
    }

    private function validateHistoricalUnits(Product $product, Request $request): void
    {
        $hasHistory=$product->stocks()->where('quantity','>',0)->exists()
            || $product->saleItems()->exists() || $product->purchaseOrderItems()->exists()
            || $product->stockMovements()->exists() || $product->stockTransfers()->exists();
        if (!$hasHistory) return;
        $units=$product->productUnits()->lockForUpdate()->get();
        $submitted=collect($request->product_units)->keyBy('unit_id');
        $base=$units->firstWhere('is_base_unit',true);
        $newBase=(int) $request->product_units[(int) $request->base_unit_index]['unit_id'];
        $changed=$base && (int) $base->unit_id!==$newBase;
        foreach ($units as $unit) {
            $row=$submitted->get($unit->unit_id);
            if (!$row || abs((float) $row['conversion_to_base']-(float) $unit->conversion_to_base)>0.00001) {
                $changed=true;
                break;
            }
        }
        if ($changed) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'product_units'=>'Barang sudah memiliki stok atau riwayat transaksi. Satuan lama tidak dapat dihapus, satuan dasar tidak dapat diganti, dan isi/konversi satuan tidak dapat diubah. Harga dan satuan default tetap dapat diperbarui; satuan baru dapat ditambahkan.',
            ]);
        }
    }

    private function syncProductUnits(Product $product, Request $request): void
    {
        $baseIndex = (int) $request->base_unit_index;
        $purchaseIndex = (int) $request->default_purchase_unit_index;
        $saleIndex = (int) $request->default_sale_unit_index;
        $conversions = $this->calculateUnitConversions($request->product_units, $baseIndex);

        $keptIds = [];
        $indexedProductUnits = [];
        foreach ($request->product_units as $index => $unitData) {
            $productUnit = ProductUnit::updateOrCreate(
                ['product_id' => $product->id, 'unit_id' => $unitData['unit_id']],
                [
                    'contains_quantity' => $unitData['contains_quantity'] ?? 1,
                    'contains_unit_id' => null,
                    'conversion_to_base' => $conversions[$index],
                    'buy_price' => $unitData['buy_price'] ?? 0,
                    'sell_price' => $unitData['sell_price'] ?? 0,
                    'is_base_unit' => (int) $index === $baseIndex,
                    'is_default_purchase' => (int) $index === $purchaseIndex,
                    'is_default_sale' => (int) $index === $saleIndex,
                ]
            );
            $keptIds[] = $productUnit->id;
            $indexedProductUnits[$index] = $productUnit;
        }

        foreach ($request->product_units as $index => $unitData) {
            $childIndex = $unitData['contains_unit_index'] ?? null;
            $indexedProductUnits[$index]->update([
                'contains_unit_id' => $childIndex !== null && isset($indexedProductUnits[(int) $childIndex])
                    ? $indexedProductUnits[(int) $childIndex]->id
                    : null,
            ]);
        }

        $product->productUnits()->whereNotIn('id', $keptIds)->delete();
    }

    private function validateProductUnitSelection(Request $request): void
    {
        $baseIndex = (int) $request->base_unit_index;
        $purchaseIndex = (int) $request->default_purchase_unit_index;
        $saleIndex = (int) $request->default_sale_unit_index;

        foreach ([$baseIndex, $purchaseIndex, $saleIndex] as $index) {
            if (! array_key_exists($index, $request->product_units ?? [])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'product_units' => 'Pilihan satuan dasar/default tidak valid.',
                ]);
            }
        }

        if (($request->product_units[$baseIndex]['contains_unit_index'] ?? '') !== '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'product_units' => 'Satuan dasar tidak boleh berisi satuan lain.',
            ]);
        }

        $this->calculateUnitConversions($request->product_units, $baseIndex);
    }

    private function calculateUnitConversions(array $units, int $baseIndex): array
    {
        $conversions = [];
        $visiting = [];

        $resolve = function (int $index) use (&$resolve, &$conversions, &$visiting, $units, $baseIndex): float {
            if (isset($conversions[$index])) {
                return $conversions[$index];
            }

            if (isset($visiting[$index])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'product_units' => 'Rantai satuan tidak boleh berputar.',
                ]);
            }

            if (! array_key_exists($index, $units)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'product_units' => 'Rantai satuan tidak valid.',
                ]);
            }

            $visiting[$index] = true;

            if ($index === $baseIndex) {
                $conversions[$index] = 1.0;
            } else {
                $childIndex = $units[$index]['contains_unit_index'] ?? null;
                if ($childIndex === null || $childIndex === '') {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'product_units' => 'Setiap satuan selain dasar harus memilih satuan isi.',
                    ]);
                }

                $conversions[$index] = (float) $units[$index]['contains_quantity'] * $resolve((int) $childIndex);
            }

            unset($visiting[$index]);

            return $conversions[$index];
        };

        foreach (array_keys($units) as $index) {
            $resolve((int) $index);
        }

        return $conversions;
    }

    private function applyCalculatedConversions(Request $request): void
    {
        $units = $request->product_units;
        $conversions = $this->calculateUnitConversions($units, (int) $request->base_unit_index);

        foreach ($conversions as $index => $conversion) {
            $units[$index]['conversion_to_base'] = $conversion;
        }

        $request->merge(['product_units' => $units]);
    }

    private function applyCalculatedBuyPrices(Request $request): void
    {
        $units = $request->product_units;
        $purchaseIndex = (int) $request->default_purchase_unit_index;
        $purchasePrice = (float) $units[$purchaseIndex]['buy_price'];
        $purchaseConversion = (float) $units[$purchaseIndex]['conversion_to_base'];

        foreach ($units as $index => $unit) {
            $units[$index]['buy_price'] = round(
                $purchasePrice * (float) $unit['conversion_to_base'] / $purchaseConversion,
                2
            );
        }

        $request->merge(['product_units' => $units]);
    }

    private function applyMinimumStockConversions(Request $request): void
    {
        $units = $request->product_units;

        $toBase = function (float $quantity, mixed $unitIndex) use ($units): float {
            $conversion = isset($units[(int) $unitIndex])
                ? (float) ($units[(int) $unitIndex]['conversion_to_base'] ?? 1)
                : 1.0;

            return $quantity * max($conversion, 1.0);
        };

        $request->merge([
            'min_stock_total' => $toBase((float) ($request->min_stock_total ?? 0), $request->min_stock_total_unit_index),
            'locations' => collect($request->input('locations', []))
                ->mapWithKeys(fn ($quantity, $locationId) => [
                    $locationId => $toBase(
                        (float) ($quantity ?? 0),
                        $request->input("location_units.{$locationId}")
                    ),
                ])
                ->all(),
        ]);
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
