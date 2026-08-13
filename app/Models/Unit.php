<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $fillable = ['name'];

    public function productsAsBuyUnit()
    {
        return $this->hasMany(Product::class, 'buy_unit_id');
    }

    public function productsAsRetailUnit()
    {
        return $this->hasMany(Product::class, 'retail_unit_id');
    }

    public function productUnits()
    {
        return $this->hasMany(ProductUnit::class);
    }

    // Cek apakah satuan masih dipakai produk
    public function isUsed(): bool
    {
        return $this->productsAsBuyUnit()->exists()
            || $this->productsAsRetailUnit()->exists()
            || $this->productUnits()->exists();
    }
}
