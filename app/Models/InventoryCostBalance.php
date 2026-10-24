<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryCostBalance extends Model
{
    protected $primaryKey = 'product_id';
    public $incrementing = false;
    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:4', 'inventory_value' => 'decimal:2', 'average_cost' => 'decimal:10', 'last_event_date' => 'date', 'activated_at' => 'datetime'];
    public function product() { return $this->belongsTo(Product::class); }
}
