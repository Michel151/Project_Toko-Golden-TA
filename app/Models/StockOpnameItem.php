<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockOpnameItem extends Model
{
    protected $fillable = [
        'stock_opname_id',
        'product_id',
        'system_quantity',
        'physical_quantity',
        'difference',
        'tracks_expiry',
        'valuation_unit_cost',
        'notes',
    ];

    protected $casts = [
        'system_quantity'   => 'decimal:4',
        'physical_quantity' => 'decimal:4',
        'difference'        => 'decimal:4',
        'tracks_expiry'     => 'boolean',
        'valuation_unit_cost' => 'decimal:10',
    ];

    public function stockOpname()
    {
        return $this->belongsTo(StockOpname::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function expiryCounts()
    {
        return $this->hasMany(StockOpnameExpiry::class)->orderByRaw('expired_date IS NULL')->orderBy('expired_date')->orderBy('id');
    }

    public function hasCountDifference(): bool
    {
        return round((float) $this->physical_quantity - (float) $this->system_quantity, 4) != 0
            || $this->expiryCounts->contains(fn ($entry) =>
                round((float) $entry->physical_quantity - (float) $entry->system_quantity, 4) != 0);
    }
}
