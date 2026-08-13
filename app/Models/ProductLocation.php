<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductLocation extends Model
{
    protected $fillable = ['product_id', 'location_id', 'min_stock'];

    protected $casts = [
        'min_stock' => 'decimal:4',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    // Cek apakah stok di lokasi ini di bawah minimum
    public function isBelowMinStock(): bool
    {
        $stock = Stock::where('product_id', $this->product_id)
                      ->where('location_id', $this->location_id)
                      ->first();

        return $stock && $stock->quantity <= $this->min_stock;
    }
}
