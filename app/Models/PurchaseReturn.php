<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class PurchaseReturn extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['approved_at' => 'datetime', 'dispatched_at' => 'datetime'];

    public static function generateReturnNumber(?\DateTimeInterface $issuedAt = null): string
    {
        $date = $issuedAt ? Carbon::instance($issuedAt)->setTimezone('Asia/Jakarta') : now('Asia/Jakarta');
        $prefix = 'RT/'.$date->format('Y/m').'/';
        $last = static::where('return_number', 'like', "{$prefix}%")
            ->selectRaw('MAX(CAST(SUBSTR(return_number, 12) AS DECIMAL(20,0))) AS sequence')->value('sequence');
        $seq = (int) $last + 1;
        return $prefix.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }

    public function purchaseReceipt()
    {
        return $this->belongsTo(PurchaseReceipt::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function replacementOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'replacement_purchase_order_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function dispatchedBy()
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }
}
