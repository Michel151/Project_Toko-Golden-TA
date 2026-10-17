<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReceipt extends Model
{
    public function inspections() { return $this->hasMany(PurchaseInspection::class); }
    public function returns() { return $this->hasMany(PurchaseReturn::class); }
    protected $fillable = [
        'submission_token',
        'purchase_order_id',
        'receipt_date',
        'received_by',
        'notes',
    ];

    protected $casts = [
        'receipt_date' => 'date',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items()
    {
        return $this->hasMany(PurchaseReceiptItem::class);
    }
}
