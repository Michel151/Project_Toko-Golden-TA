<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleItemSource extends Model
{
    protected $fillable = ['sale_item_id', 'location_id', 'stock_batch_id', 'quantity', 'base_quantity'];

    protected $casts = [
        'quantity' => 'decimal:4',
        'base_quantity' => 'decimal:4',
    ];

    // Mengambil rincian penjualan yang memakai sumber stok ini.
    public function saleItem()
    {
        return $this->belongsTo(SaleItem::class);
    }

    // Mengambil lokasi asal stok yang digunakan.
    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    // Mengambil batch asal barang jika penjualan menggunakan pelacakan kedaluwarsa.
    public function stockBatch()
    {
        return $this->belongsTo(StockBatch::class);
    }
}
