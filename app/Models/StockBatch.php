<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockBatch extends Model
{
    protected $fillable = [
        'product_id',
        'location_id',
        'purchase_receipt_item_id',
        'received_date',
        'expired_date',
        'quantity_initial',
        'quantity_remaining',
    ];

    protected $casts = [
        'received_date' => 'date',
        'expired_date' => 'date',
        'quantity_initial' => 'decimal:4',
        'quantity_remaining' => 'decimal:4',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function purchaseReceiptItem()
    {
        return $this->belongsTo(PurchaseReceiptItem::class);
    }

    public function saleItemSources()
    {
        return $this->hasMany(SaleItemSource::class);
    }

    public function getExpiryStatusAttribute(): string
    {
        if (! $this->expired_date) {
            return 'Tidak Ada Kadaluarsa';
        }

        if ($this->expired_date->lt(today())) {
            return 'Kadaluarsa';
        }

        if ($this->expired_date->lte(today()->addDays(30))) {
            return 'Hampir Kadaluarsa';
        }

        return 'Aman';
    }

    public function scopeSellable($query)
    {
        return $query->where('quantity_remaining', '>', 0)
            ->where(function ($query) {
                $query->whereNull('expired_date')
                    ->orWhereDate('expired_date', '>=', today());
            });
    }
}
