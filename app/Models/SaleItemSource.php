<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleItemSource extends Model
{
    protected $fillable = ['sale_item_id', 'location_id', 'stock_batch_id', 'quantity'];

    protected $casts = [
        'quantity' => 'decimal:4',
    ];

    public function saleItem()
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function stockBatch()
    {
        return $this->belongsTo(StockBatch::class);
    }
}
