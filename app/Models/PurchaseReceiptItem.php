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
    ];

    public function purchaseReceipt()
    {
        return $this->belongsTo(PurchaseReceipt::class);
    }

    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function productUnit()
    {
        return $this->belongsTo(ProductUnit::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function stockBatch()
    {
        return $this->hasOne(StockBatch::class);
    }
}
