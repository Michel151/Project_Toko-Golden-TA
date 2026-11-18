<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $fillable = ['name'];

    // Menemukan barang yang memilih satuan ini untuk pembelian.
    public function productsAsBuyUnit()
    {
        return $this->hasMany(Product::class, 'buy_unit_id');
    }

    // Menemukan barang yang memilih satuan ini untuk penjualan ecer.
    public function productsAsRetailUnit()
    {
        return $this->hasMany(Product::class, 'retail_unit_id');
    }

    // Menemukan konfigurasi kemasan yang memakai satuan ini.
    public function productUnits()
    {
        return $this->hasMany(ProductUnit::class);
    }

    // Memeriksa apakah satuan masih dipakai sebelum dihapus.
    public function isUsed(): bool
    {
        return $this->productsAsBuyUnit()->exists()
            || $this->productsAsRetailUnit()->exists()
            || $this->productUnits()->exists();
    }
}
