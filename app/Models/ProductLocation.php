<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductLocation extends Model
{
    // min_stock adalah batas per pasangan produk dan lokasi, dalam satuan dasar.
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

    // Memeriksa apakah stok di satu lokasi sudah mencapai batas peringatan.
    public function isBelowMinStock(): bool
    {
        $stock = Stock::where('product_id', $this->product_id)
                      ->where('location_id', $this->location_id)
                      ->first();

        return $stock && $stock->quantity <= $this->min_stock;
    }
}
