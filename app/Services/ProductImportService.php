<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductLocation;
use App\Models\ProductUnit;
use App\Models\Supplier;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductImportService
{
    public const REQUIRED_HEADINGS = ['nama_barang', 'barcode', 'kategori', 'satuan_dasar', 'harga_beli', 'harga_jual', 'lokasi', 'stok_aktual', 'stok_minimum'];

    public const HEADINGS = [...self::REQUIRED_HEADINGS, 'supplier'];

    public function import(array $rows, bool $importStock): array
    {
        $headings = array_map(fn ($value) => trim((string) $value), array_shift($rows) ?? []);
        if (count(array_unique($headings)) !== count($headings) || array_diff(self::REQUIRED_HEADINGS, $headings)) {
            $this->fail('Baris 1 harus berisi semua judul kolom sesuai template, tanpa judul ganda.');
        }
        if (count($rows) > 5000) {
            $this->fail('Maksimal 5.000 baris per impor. Pisahkan data ke beberapa file.');
        }

        return DB::transaction(function () use ($rows, $headings, $importStock) {
            $result = ['created' => 0, 'existing' => 0, 'stocks' => 0];
            $seen = [];
            $reference = 'IMPORT-' . now()->format('Ymd-His');
            foreach ($rows as $index => $values) {
                if (! array_filter($values, fn ($value) => $value !== null && trim((string) $value) !== '')) {
                    continue;
                }
                $line = $index + 2;
                $data = [];
                foreach ($headings as $column => $heading) {
                    $value = $values[$column] ?? null;
                    $data[$heading] = is_string($value) ? trim($value) : $value;
                }
                $validator = Validator::make($data, [
                    'nama_barang' => 'required|string|max:200',
                    'barcode' => 'nullable|max:100',
                    'kategori' => 'required|string|max:255',
                    'satuan_dasar' => 'required|string|max:255',
                    'harga_beli' => 'required|numeric|min:0|max:9999999999999.99',
                    'harga_jual' => 'required|numeric|min:0|max:9999999999999.99',
                    'lokasi' => 'required|string|max:255',
                    'stok_aktual' => 'nullable|numeric|min:0|max:99999999999.9999',
                    'stok_minimum' => 'nullable|numeric|min:0|max:99999999999.9999',
                    'supplier' => 'nullable|string|max:2000',
                ]);
                if ($validator->fails()) {
                    $this->fail("Baris {$line}: " . implode(' ', $validator->errors()->all()));
                }

                $barcode = (string) ($data['barcode'] ?? '');
                $matches = $barcode !== ''
                    ? Product::where('barcode', $barcode)->lockForUpdate()->get()
                    : Product::where('name', $data['nama_barang'])->lockForUpdate()->get();
                if ($matches->count() > 1) {
                    $this->fail("Baris {$line}: nama barang tidak unik. Isi barcode untuk menentukan barang.");
                }
                $product = $matches->first();
                if (! $product && $barcode !== '' && Product::where('name', $data['nama_barang'])->exists()) {
                    $this->fail("Baris {$line}: nama barang sudah ada dengan barcode lain. Gunakan barcode yang tersimpan.");
                }
                if ($product) {
                    $baseUnit = $product->baseProductUnit?->unit ?? ($product->can_retail ? $product->retailUnit : $product->buyUnit);
                    if (mb_strtolower($baseUnit?->name ?? '') !== mb_strtolower($data['satuan_dasar'])) {
                        $this->fail("Baris {$line}: satuan dasar harus sesuai barang yang tersimpan ({$baseUnit?->name}).");
                    }
                    if (mb_strtolower($product->name) !== mb_strtolower($data['nama_barang'])) {
                        $this->fail("Baris {$line}: barcode cocok dengan barang lain ({$product->name}).");
                    }
                    $result['existing']++;
                } else {
                    $category = Category::firstOrCreate(['name' => $data['kategori']]);
                    $unit = Unit::firstOrCreate(['name' => $data['satuan_dasar']]);
                    $product = Product::create([
                        'name' => $data['nama_barang'], 'barcode' => $barcode ?: null,
                        'category_id' => $category->id, 'buy_unit_id' => $unit->id,
                        'retail_unit_id' => $unit->id, 'conversion_factor' => 1,
                        'buy_price' => $data['harga_beli'], 'sell_price_wholesale' => $data['harga_jual'],
                        'sell_price_retail' => $data['harga_jual'], 'can_retail' => false,
                        'is_active' => true, 'is_expirable' => false,
                        'min_stock_total' => $data['stok_minimum'] ?: 0,
                    ]);
                    ProductUnit::create([
                        'product_id' => $product->id, 'unit_id' => $unit->id,
                        'contains_quantity' => 1, 'conversion_to_base' => 1,
                        'buy_price' => $data['harga_beli'], 'sell_price' => $data['harga_jual'],
                        'is_base_unit' => true, 'is_default_purchase' => true, 'is_default_sale' => true,
                    ]);
                    if (! $product->barcode) {
                        $product->updateQuietly(['barcode' => 'TG' . str_pad((string) $product->id, 10, '0', STR_PAD_LEFT)]);
                    }
                    $result['created']++;
                }
                $this->linkSuppliers($product, $data['supplier'] ?? null, $line);
                $location = Location::firstOrCreate(['name' => $data['lokasi']]);
                $key = $product->id . ':' . $location->id;
                if (isset($seen[$key])) {
                    $this->fail("Baris {$line}: barang dan lokasi sudah tercantum pada baris {$seen[$key]}.");
                }
                $seen[$key] = $line;
                ProductLocation::firstOrCreate(['product_id' => $product->id, 'location_id' => $location->id], ['min_stock' => 0]);
                $product->stocks()->firstOrCreate(['location_id' => $location->id], ['quantity' => 0]);
                if ($importStock && $data['stok_aktual'] !== null && $data['stok_aktual'] !== '') {
                    app(StockService::class)->adjustStock($product, $location, (float) $data['stok_aktual'], referenceNumber: $reference, valuationUnitCost: (string) $data['harga_beli']);
                    $result['stocks']++;
                }
            }
            if (! $seen) {
                $this->fail('File tidak berisi data barang.');
            }
            return $result;
        });
    }

    private function linkSuppliers(Product $product, ?string $names, int $line): void
    {
        $seen = [];
        foreach (explode(';', $names ?? '') as $name) {
            $name = trim($name);
            $key = mb_strtolower($name);
            if ($name === '' || isset($seen[$key])) {
                continue;
            }
            if (mb_strlen($name) > 255) {
                $this->fail("Baris {$line}: setiap nama supplier maksimal 255 karakter.");
            }
            $seen[$key] = true;
            $matches = Supplier::whereRaw('LOWER(name) = ?', [$key])->get();
            if ($matches->count() > 1) {
                $this->fail("Baris {$line}: nama supplier {$name} tidak unik. Rapikan data supplier sebelum impor.");
            }
            $supplier = $matches->first() ?? Supplier::create(['name' => $name]);
            if (! $product->suppliers()->whereKey($supplier->id)->exists()) {
                $product->suppliers()->attach($supplier->id, [
                    'buy_price' => $product->defaultPurchaseUnit?->buy_price ?? $product->buy_price,
                ]);
            }
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
