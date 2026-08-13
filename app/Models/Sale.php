<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $fillable = [
        'sale_number',
        'customer_name',
        'sale_date',
        'total_amount',
        'payment_method',
        'amount_paid',
        'change_amount',
        'created_by',
    ];

    protected $casts = [
        'sale_date'     => 'date',
        'total_amount'  => 'decimal:2',
        'amount_paid'   => 'decimal:2',
        'change_amount' => 'decimal:2',
    ];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    // Generate sale number: INV-2024-000001
    public static function generateSaleNumber(): string
    {
        $year   = date('Y');
        $prefix = "INV-{$year}-";
        $last   = static::where('sale_number', 'like', "{$prefix}%")
                        ->orderByDesc('id')
                        ->value('sale_number');
        $seq = $last ? (int) substr($last, -6) + 1 : 1;
        return $prefix . str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
