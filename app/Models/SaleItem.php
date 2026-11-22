<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id',
        'product_id',
        'product_unit_id',
        'sale_type',
        'quantity',
        'unit_price',
        'cost_price',
        'subtotal',
        'cost_subtotal',
        'cost_method', 'cost_base_quantity', 'cost_per_base_unit',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'cost_subtotal' => 'decimal:2',
        'cost_base_quantity' => 'decimal:4', 'cost_per_base_unit' => 'decimal:10',
    ];

    // Mengambil dokumen penjualan pemilik rincian ini.
    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    // Mengambil barang yang dijual pada rincian ini.
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Mengambil satuan produk yang digunakan pada rincian penjualan.
    public function productUnit()
    {
        return $this->belongsTo(ProductUnit::class);
    }

    // Mengambil lokasi, batch, dan jumlah stok yang dipakai rincian ini.
    public function sources()
    {
        return $this->hasMany(SaleItemSource::class);
    }
}
