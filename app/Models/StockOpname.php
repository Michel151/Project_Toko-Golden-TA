<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockOpname extends Model
{
    protected $fillable = [
        'opname_number',
        'location_id',
        'opname_date',
        'status',
        'created_by',
    ];

    protected $casts = [
        'opname_date' => 'date',
    ];

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items()
    {
        return $this->hasMany(StockOpnameItem::class);
    }

    // Generate opname number: OPN-2024-000001
    public static function generateOpnameNumber(): string
    {
        $year   = date('Y');
        $prefix = "OPN-{$year}-";
        $last   = static::where('opname_number', 'like', "{$prefix}%")
                        ->orderByDesc('id')
                        ->value('opname_number');
        $seq = $last ? (int) substr($last, -6) + 1 : 1;
        return $prefix . str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
