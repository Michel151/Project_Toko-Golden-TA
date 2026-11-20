<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'product_unit_id',
        'quantity_ordered',
        'quantity_received',
        'buy_price',
        'quantity_reserved',
        'source_purchase_order_item_id',
    ];

    protected $casts = [
        'quantity_ordered'  => 'decimal:4',
        'quantity_received' => 'decimal:4',
        'buy_price'         => 'decimal:2',
        'quantity_reserved' => 'decimal:4',
    ];

    // Mengambil PO yang menjadi asal data ini.
    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    // Mengambil barang yang tercatat pada rincian ini.
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Mengambil satuan produk yang digunakan pada rincian ini.
    public function productUnit()
    {
        return $this->belongsTo(ProductUnit::class);
    }

    // Mengambil rincian penerimaan barang baik untuk baris pesanan ini.
    public function receiptItems()
    {
        return $this->hasMany(PurchaseReceiptItem::class);
    }

    // Mengambil baris pesanan sebelumnya yang harus dipenuhi oleh barang pengganti ini.
    public function sourceItem() { return $this->belongsTo(self::class, 'source_purchase_order_item_id'); }

    // Menghitung jumlah yang masih boleh diperiksa setelah mengurangi penerimaan baik dan cadangan masalah.
    public function receivableQuantity(): float
    {
        return max(0, round($this->remainingQuantity() - (float) $this->quantity_reserved, 4));
    }

    // Menghitung jumlah pesanan yang belum dipenuhi dengan barang baik.
    public function remainingQuantity(): float
    {
        return max(0, (float) $this->quantity_ordered - (float) $this->quantity_received);
    }

    // Apakah sudah selesai diterima semua
    // Memeriksa apakah jumlah barang baik sudah memenuhi jumlah yang dipesan.
    public function isCompleted(): bool
    {
        return $this->quantity_received >= $this->quantity_ordered;
    }
}
