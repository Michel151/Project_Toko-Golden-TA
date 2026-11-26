<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryCostBalance extends Model
{
    // Satu saldo biaya untuk satu produk, sehingga kunci utamanya adalah ID produk.
    protected $primaryKey = 'product_id';
    public $incrementing = false;
    protected $guarded = [];
    // Menjaga jumlah dan biaya sebagai desimal serta menyediakan tanggal aktivasi.
    protected $casts = ['quantity' => 'decimal:4', 'inventory_value' => 'decimal:2', 'average_cost' => 'decimal:10', 'last_event_date' => 'date', 'activated_at' => 'datetime'];
    // Mengambil produk pemilik saldo biaya ini.
    public function product() { return $this->belongsTo(Product::class); }
}
