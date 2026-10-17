<?php

namespace App\Models;

use Carbon\Carbon;
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
        'root_purchase_order_id',
    ];

    protected $casts = [
        'order_date' => 'date',
    ];

    public function getStatusLabelAttribute(): string
    {
        return ['pending'=>'Menunggu', 'partial'=>'Diterima Sebagian', 'completed'=>'Selesai'][$this->status] ?? $this->status;
    }

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

    public function rootOrder() { return $this->belongsTo(self::class, 'root_purchase_order_id'); }
    public function replacementOrders() { return $this->hasMany(self::class, 'root_purchase_order_id'); }

    public function canModify(): bool
    {
        return $this->status === 'pending' && ! $this->root_purchase_order_id
            && ! $this->receipts()->exists() && ! $this->replacementOrders()->exists();
    }

    public static function generatePoNumber(?\DateTimeInterface $issuedAt = null): string
    {
        $date = $issuedAt ? Carbon::instance($issuedAt)->setTimezone('Asia/Jakarta') : now('Asia/Jakarta');
        $prefix = 'PO/'.$date->format('Y/m').'/';
        $last = static::whereNull('root_purchase_order_id')
            ->where('po_number', 'like', "{$prefix}%")
            ->selectRaw('MAX(CAST(SUBSTR(po_number, 12) AS DECIMAL(20,0))) AS sequence')->value('sequence');
        $seq = (int) $last + 1;
        return $prefix . str_pad($seq, 6, '0', STR_PAD_LEFT);
    }

    // Update status berdasarkan item yang sudah diterima
    public function updateStatus(): void
    {
        $allCompleted = $this->items->every(fn($item) =>
            $item->quantity_received >= $item->quantity_ordered
        );
        $anyReceived = $this->items->some(fn($item) =>
            $item->quantity_received > 0 || $item->quantity_reserved > 0
        );

        $this->status = $allCompleted ? 'completed' : ($anyReceived ? 'partial' : 'pending');
        $this->save();
    }

    // Total nilai PO
    public function totalValue(): float
    {
        if ($this->root_purchase_order_id) {
            return 0;
        }
        return (float) $this->items->sum(fn($item) =>
            $item->quantity_ordered * $item->buy_price
        );
    }
}
