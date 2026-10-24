<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id',
        'product_id',
        'product_unit_id',
        'sale_type',
        'quantity',
        'unit_price',
        'cost_price',
        'subtotal',
        'cost_subtotal',
        'cost_method', 'cost_base_quantity', 'cost_per_base_unit',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'cost_subtotal' => 'decimal:2',
        'cost_base_quantity' => 'decimal:4', 'cost_per_base_unit' => 'decimal:10',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function productUnit()
    {
        return $this->belongsTo(ProductUnit::class);
    }

    public function sources()
    {
        return $this->hasMany(SaleItemSource::class);
    }
}
