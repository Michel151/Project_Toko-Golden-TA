<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $fillable = ['name'];

    // Pakai buy_unit_id karena barang juga memiliki kolom terpisah untuk satuan ecer.
    public function productsAsBuyUnit()
    {
        return $this->hasMany(Product::class, 'buy_unit_id');
    }

    // Hitung pemakaian sebagai satuan ecer lewat retail_unit_id.
    public function productsAsRetailUnit()
    {
        return $this->hasMany(Product::class, 'retail_unit_id');
    }

    // Konfigurasi multi satuan memakai product_units.unit_id untuk merujuk master satuan.
    public function productUnits()
    {
        return $this->hasMany(ProductUnit::class);
    }

    // Cek apakah satuan masih dipakai produk
    // Jika satuan masih dipakai sebagai satuan beli, satuan ecer, atau pilihan kemasan, penghapusan ditolak.
    public function isUsed(): bool
    {
        return $this->productsAsBuyUnit()->exists()
            || $this->productsAsRetailUnit()->exists()
            || $this->productUnits()->exists();
    }
}
