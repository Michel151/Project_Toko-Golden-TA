<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $fillable = [
        'product_id', 'location_id', 'movement_type', 'quantity',
        'balance_before', 'balance_after', 'movement_date',
        'reference_type', 'reference_id', 'reference_number', 'notes', 'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'balance_before' => 'decimal:4',
        'balance_after' => 'decimal:4',
        'movement_date' => 'date',
    ];

    public function product() { return $this->belongsTo(Product::class); }
    public function location() { return $this->belongsTo(Location::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
    public function reference() { return $this->morphTo(); }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->movement_type) {
            'purchase' => 'Penerimaan',
            'sale' => 'Penjualan',
            'transfer_in' => 'Transfer Masuk',
            'transfer_out' => 'Transfer Keluar',
            'opname_adjustment' => 'Penyesuaian Opname',
            default => ucwords(str_replace('_', ' ', $this->movement_type)),
        };
    }
}
