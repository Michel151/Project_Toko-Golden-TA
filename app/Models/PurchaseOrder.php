<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'po_number',
        'supplier_id',
        'order_date',
        'status',
        'created_by',
    ];

    protected $casts = [
        'order_date' => 'date',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receipts()
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    // Generate PO Number: PO-2024-000001
    public static function generatePoNumber(): string
    {
        $year   = date('Y');
        $prefix = "PO-{$year}-";
        $last   = static::where('po_number', 'like', "{$prefix}%")
                        ->orderByDesc('id')
                        ->value('po_number');
        $seq = $last ? (int) substr($last, -6) + 1 : 1;
        return $prefix . str_pad($seq, 6, '0', STR_PAD_LEFT);
    }

    // Update status berdasarkan item yang sudah diterima
    public function updateStatus(): void
    {
        $allCompleted = $this->items->every(fn($item) =>
            $item->quantity_received >= $item->quantity_ordered
        );
        $anyReceived = $this->items->some(fn($item) =>
            $item->quantity_received > 0
        );

        $this->status = $allCompleted ? 'completed' : ($anyReceived ? 'partial' : 'pending');
        $this->save();
    }

    // Total nilai PO
    public function totalValue(): float
    {
        return (float) $this->items->sum(fn($item) =>
            $item->quantity_ordered * $item->buy_price
        );
    }
}
