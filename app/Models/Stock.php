<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    // quantity adalah saldo satu produk pada satu lokasi; batas minimumnya berada di ProductLocation.
    protected $fillable = ['product_id', 'location_id', 'quantity'];

    protected $casts = [
        'quantity' => 'decimal:4',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }
}
