<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransfer extends Model
{
    protected $fillable = [
        'transfer_number',
        'product_id',
        'product_unit_id',
        'from_location_id',
        'to_location_id',
        'quantity',
        'transfer_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quantity'      => 'decimal:4',
        'transfer_date' => 'date',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function productUnit()
    {
        return $this->belongsTo(ProductUnit::class);
    }

    public function fromLocation()
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation()
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Generate transfer number: TRF-2024-000001
    public static function generateTransferNumber(): string
    {
        $year   = date('Y');
        $prefix = "TRF-{$year}-";
        $last   = static::where('transfer_number', 'like', "{$prefix}%")
                        ->orderByDesc('id')
                        ->value('transfer_number');
        $seq = $last ? (int) substr($last, -6) + 1 : 1;
        return $prefix . str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
