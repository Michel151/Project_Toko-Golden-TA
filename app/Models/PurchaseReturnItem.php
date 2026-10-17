<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturnItem extends Model
{
    public const KINDS = ['damaged' => 'Rusak', 'wrong' => 'Tidak sesuai', 'missing' => 'Kekurangan Kiriman'];

    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'decimal:4'];

    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function purchaseInspection()
    {
        return $this->belongsTo(PurchaseInspection::class);
    }
}
