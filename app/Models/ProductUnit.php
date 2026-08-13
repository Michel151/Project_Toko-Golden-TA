<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductUnit extends Model
{
    protected $fillable = [
        'product_id',
        'unit_id',
        'contains_quantity',
        'contains_unit_id',
        'conversion_to_base',
        'buy_price',
        'sell_price',
        'is_base_unit',
        'is_default_purchase',
        'is_default_sale',
    ];

    protected $casts = [
        'conversion_to_base' => 'decimal:4',
        'contains_quantity' => 'decimal:4',
        'buy_price' => 'decimal:2',
        'sell_price' => 'decimal:2',
        'is_base_unit' => 'boolean',
        'is_default_purchase' => 'boolean',
        'is_default_sale' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function containsUnit()
    {
        return $this->belongsTo(ProductUnit::class, 'contains_unit_id');
    }
}
