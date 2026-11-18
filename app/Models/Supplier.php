<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    // Izinkan identitas supplier diisi dari form.
    protected $fillable = ['name', 'address', 'phone'];

    // Mengambil daftar barang yang dipasok oleh supplier ini.
    public function products()
    {
        return $this->belongsToMany(Product::class)
            ->withPivot('buy_price')
            ->withTimestamps();
    }

    // Mengambil pesanan pembelian yang dibuat untuk supplier ini.
    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
