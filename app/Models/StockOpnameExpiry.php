<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockOpnameExpiry extends Model
{
    protected $guarded=['id'];

    protected $casts=[
        'expired_date'=>'date', 'system_quantity'=>'decimal:4',
        'physical_quantity'=>'decimal:4', 'batch_snapshot'=>'array',
    ];

    public function stockOpnameItem() { return $this->belongsTo(StockOpnameItem::class); }
}
