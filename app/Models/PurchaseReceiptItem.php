<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReceiptItem extends Model
{
    protected $fillable = [
        'purchase_receipt_id',
        'purchase_order_item_id',
        'product_id',
        'product_unit_id',
        'location_id',
        'quantity_received',
        'expired_date',
    ];

    protected $casts = [
        'quantity_received' => 'decimal:4',
        'expired_date' => 'date',
        'cost_base_quantity' => 'decimal:4',
        'acquisition_value' => 'decimal:2',
    ];

    // Mengambil dokumen penerimaan asal data ini.
    public function purchaseReceipt()
    {
        return $this->belongsTo(PurchaseReceipt::class);
    }

    // Mengambil baris PO yang menjadi asal rincian atau pemeriksaan ini.
    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class);
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

    // Mengambil lokasi tujuan barang pada rincian penerimaan.
    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    // Mengambil batch stok yang dibuat dari rincian penerimaan ini.
    public function stockBatch()
    {
        return $this->hasOne(StockBatch::class);
    }
}
