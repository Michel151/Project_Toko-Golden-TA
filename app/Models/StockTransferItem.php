<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransferItem extends Model
{
    protected $fillable = ['product_id', 'product_unit_id', 'quantity', 'expiry_details'];

    // Jumlah disimpan dalam satuan dasar; rincian batch dibaca sebagai array.
    protected $casts = ['quantity' => 'decimal:4', 'expiry_details' => 'array'];

    // Mengambil dokumen transfer induk dari rincian ini.
    public function stockTransfer()
    {
        return $this->belongsTo(StockTransfer::class);
    }

    // Mengambil barang pada rincian transfer.
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Mengambil satuan produk yang dipilih pengguna saat transfer.
    public function productUnit()
    {
        return $this->belongsTo(ProductUnit::class);
    }
}
