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
        'confirmed_by', 'confirmed_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason',
        'closed_by', 'closed_at', 'closure_reason',
    ];

    protected $casts = [
        'order_date' => 'date',
        'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime', 'closed_at' => 'datetime',
    ];

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public const STATUS_LABELS = ['draft'=>'Draf', 'pending'=>'Menunggu', 'partial'=>'Diterima Sebagian', 'completed'=>'Selesai', 'cancelled'=>'Dibatalkan', 'closed'=>'Sisa Pesanan Ditutup'];
    public const STATUS_COLORS = ['draft'=>'secondary', 'pending'=>'warning', 'partial'=>'info', 'completed'=>'success', 'cancelled'=>'danger', 'closed'=>'secondary'];

    public function confirmedBy() { return $this->belongsTo(User::class, 'confirmed_by'); }
    public function cancelledBy() { return $this->belongsTo(User::class, 'cancelled_by'); }
    public function closedBy() { return $this->belongsTo(User::class, 'closed_by'); }

    public function canCancel(): bool
    {
        return in_array($this->status, ['draft', 'pending'], true) && !$this->root_purchase_order_id
            && !$this->receipts()->exists() && !$this->replacementOrders()->exists()
            && !$this->items()->where(fn ($q) => $q->where('quantity_received', '>', 0)->orWhere('quantity_reserved', '>', 0))->exists();
    }

    public function canCloseRemainder(): bool
    {
        return $this->status === 'partial' && !$this->root_purchase_order_id
            && !$this->items()->where('quantity_reserved', '>', 0)->exists()
            && $this->items()->where('quantity_received', '>', 0)->exists()
            && $this->items()->whereColumn('quantity_received', '<', 'quantity_ordered')->exists()
            && !$this->replacementOrders()->whereIn('status', ['pending', 'partial'])->exists();
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
        return $this->status === 'draft' && ! $this->root_purchase_order_id
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
        if (in_array($this->status, ['draft', 'cancelled', 'closed'], true)) return;
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
