<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    // Identitas supplier disimpan di suppliers; pilihan barang disimpan lewat relasi.
    protected $fillable = ['name', 'address', 'phone'];

    // Satu supplier bisa menjual banyak barang, dan satu barang bisa dijual banyak supplier.
    // buy_price di product_supplier adalah harga untuk satu pasangan. Contoh: CV Maju menjual Gula Rp14.500.
    public function products()
    {
        return $this->belongsToMany(Product::class)
            ->withPivot('buy_price')
            ->withTimestamps();
    }

    // Satu supplier dapat dirujuk banyak PO melalui purchase_orders.supplier_id.
    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
