<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductUnit extends Model
{
    // Contoh: baris Dus milik Gula menyimpan isi 12 Pcs, harga per Dus, dan pilihan apakah Dus dipakai untuk beli.
    protected $fillable = [
        'product_id',
        'unit_id',
        'contains_quantity',
        'contains_unit_id',
        'conversion_to_base',
        'buy_price',
        'sell_price',
        'is_base_unit',
        'is_default_purchase',
        'is_default_sale',
    ];

    // Gunakan empat angka desimal untuk jumlah dan konversi, dua untuk harga, dan true/false untuk pilihan Dasar, Beli, serta Jual.
    protected $casts = [
        'conversion_to_base' => 'decimal:4',
        'contains_quantity' => 'decimal:4',
        'buy_price' => 'decimal:2',
        'sell_price' => 'decimal:2',
        'is_base_unit' => 'boolean',
        'is_default_purchase' => 'boolean',
        'is_default_sale' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    // Contoh: Dus menunjuk ke konfigurasi Pcs milik barang ini. ID konfigurasi itu berbeda dari ID Pcs di Master Satuan.
    public function containsUnit()
    {
        return $this->belongsTo(ProductUnit::class, 'contains_unit_id');
    }
}
