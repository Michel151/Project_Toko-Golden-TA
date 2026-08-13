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
    ];

    protected $casts = [
        'quantity_ordered'  => 'decimal:4',
        'quantity_received' => 'decimal:4',
        'buy_price'         => 'decimal:2',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function productUnit()
    {
        return $this->belongsTo(ProductUnit::class);
    }

    public function receiptItems()
    {
        return $this->hasMany(PurchaseReceiptItem::class);
    }

    // Sisa yang belum diterima
    public function remainingQuantity(): float
    {
        return max(0, (float) $this->quantity_ordered - (float) $this->quantity_received);
    }

    // Apakah sudah selesai diterima semua
    public function isCompleted(): bool
    {
        return $this->quantity_received >= $this->quantity_ordered;
    }
}
