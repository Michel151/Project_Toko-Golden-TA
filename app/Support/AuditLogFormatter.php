<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AuditLogFormatter
{
    private const FIELD_LABELS = [
        'name' => 'Nama',
        'email' => 'Email',
        'role' => 'Peran',
        'description' => 'Deskripsi',
        'address' => 'Alamat',
        'phone' => 'Nomor Telepon',
        'barcode' => 'Barcode',
        'category_id' => 'Kategori',
        'buy_unit_id' => 'Satuan Pembelian',
        'retail_unit_id' => 'Satuan Eceran',
        'buy_price' => 'Harga Beli',
        'sell_price_wholesale' => 'Harga Jual Grosir',
        'sell_price_retail' => 'Harga Jual Eceran',
        'conversion_factor' => 'Faktor Konversi',
        'min_stock_total' => 'Stok Minimum',
        'is_expirable' => 'Memiliki Kedaluwarsa',
        'is_active' => 'Status Aktif',
        'can_retail' => 'Dapat Dijual Eceran',
        'po_number' => 'Nomor PO',
        'supplier_id' => 'Supplier',
        'order_date' => 'Tanggal Pesanan',
        'purchase_order_id' => 'Purchase Order',
        'receipt_date' => 'Tanggal Penerimaan',
        'received_by' => 'Diterima Oleh',
        'notes' => 'Catatan',
        'sale_number' => 'Nomor Penjualan',
        'customer_name' => 'Nama Pelanggan',
        'sale_date' => 'Tanggal Penjualan',
        'total_amount' => 'Total',
        'payment_method' => 'Metode Pembayaran',
        'amount_paid' => 'Jumlah Dibayar',
        'change_amount' => 'Kembalian',
        'transfer_number' => 'Nomor Transfer',
        'product_id' => 'Barang',
        'product_unit_id' => 'Satuan Barang',
        'from_location_id' => 'Lokasi Asal',
        'to_location_id' => 'Lokasi Tujuan',
        'quantity' => 'Jumlah',
        'transfer_date' => 'Tanggal Transfer',
        'opname_number' => 'Nomor Opname',
        'location_id' => 'Lokasi',
        'opname_date' => 'Tanggal Opname',
        'status' => 'Status',
        'created_by' => 'Dibuat Oleh',
        'confirmed_by' => 'Dikonfirmasi Oleh', 'confirmed_at' => 'Waktu Konfirmasi',
        'cancelled_by' => 'Dibatalkan Oleh', 'cancelled_at' => 'Waktu Pembatalan', 'cancellation_reason' => 'Alasan Pembatalan',
        'closed_by' => 'Ditutup Oleh', 'closed_at' => 'Waktu Penutupan', 'closure_reason' => 'Alasan Penutupan Sisa Pesanan',
    ];

    private const RELATIONS = [
        'category_id' => [Category::class, 'name'],
        'buy_unit_id' => [Unit::class, 'name'],
        'retail_unit_id' => [Unit::class, 'name'],
        'supplier_id' => [Supplier::class, 'name'],
        'purchase_order_id' => [PurchaseOrder::class, 'po_number'],
        'created_by' => [User::class, 'name'],
        'confirmed_by' => [User::class, 'name'], 'cancelled_by' => [User::class, 'name'], 'closed_by' => [User::class, 'name'],
        'received_by' => [User::class, 'name'],
        'product_id' => [Product::class, 'name'],
        'product_unit_id' => [ProductUnit::class, 'id'],
        'location_id' => [Location::class, 'name'],
        'from_location_id' => [Location::class, 'name'],
        'to_location_id' => [Location::class, 'name'],
    ];

    private const CURRENCY_FIELDS = [
        'buy_price', 'sell_price_wholesale', 'sell_price_retail',
        'total_amount', 'amount_paid', 'change_amount',
    ];

    private const BOOLEAN_FIELDS = ['is_expirable', 'is_active', 'can_retail'];

    private array $relationValues = [];

    public function format(Collection $logs): array
    {
        $this->loadRelationValues($logs);

        return $logs->mapWithKeys(fn (AuditLog $log) => [
            $log->id => [
                'old' => $this->formatValues($log->old_values ?? []),
                'new' => $this->formatValues($log->new_values ?? []),
            ],
        ])->all();
    }

    private function formatValues(array $values): array
    {
        unset($values['id']);

        return collect($values)->map(function ($value, string $key) {
            return [
                'label' => self::FIELD_LABELS[$key] ?? str($key)->replace('_', ' ')->title()->toString(),
                'value' => $this->formatValue($key, $value),
            ];
        })->values()->all();
    }

    private function formatValue(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if (isset(self::RELATIONS[$key])) {
            return $this->relationValues[$key][(string) $value] ?? 'ID '.$value;
        }

        if (in_array($key, self::BOOLEAN_FIELDS, true)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Ya' : 'Tidak';
        }

        if (in_array($key, self::CURRENCY_FIELDS, true) && is_numeric($value)) {
            return 'Rp '.number_format((float) $value, 0, ',', '.');
        }

        if (str_ends_with($key, '_date')) {
            try {
                return Carbon::parse($value)->locale('id')->translatedFormat('d F Y');
            } catch (\Throwable) {
                // Preserve the recorded value when it is not a valid date.
            }
        }

        if ($key === 'status') {
            return match ((string) $value) {
                'draft' => 'Draft',
                'pending' => 'Menunggu',
                'partial' => 'Diterima Sebagian',
                'completed' => 'Selesai',
                'cancelled' => 'Dibatalkan',
                'closed' => 'Sisa Pesanan Ditutup',
                default => ucfirst(str_replace('_', ' ', (string) $value)),
            };
        }

        if ($key === 'role') {
            return match ((string) $value) {
                'owner' => 'Owner',
                'kasir' => 'Kasir',
                'staf_gudang' => 'Staf Gudang',
                default => ucfirst(str_replace('_', ' ', (string) $value)),
            };
        }

        if ($key === 'payment_method') {
            return ucfirst(str_replace('_', ' ', (string) $value));
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }

    private function loadRelationValues(Collection $logs): void
    {
        foreach (self::RELATIONS as $key => [$model, $displayColumn]) {
            $ids = $logs->flatMap(function (AuditLog $log) use ($key) {
                return [data_get($log->old_values, $key), data_get($log->new_values, $key)];
            })->filter(fn ($id) => $id !== null && $id !== '')->unique()->values();

            if ($ids->isEmpty()) {
                continue;
            }

            if ($model === ProductUnit::class) {
                $this->relationValues[$key] = ProductUnit::with('unit')
                    ->whereIn('id', $ids)
                    ->get()
                    ->mapWithKeys(fn (ProductUnit $productUnit) => [
                        (string) $productUnit->id => $productUnit->unit?->name ?? 'ID '.$productUnit->id,
                    ])->all();

                continue;
            }

            $this->relationValues[$key] = $model::query()
                ->whereIn('id', $ids)
                ->pluck($displayColumn, 'id')
                ->mapWithKeys(fn ($label, $id) => [(string) $id => (string) $label])
                ->all();
        }
    }
}
