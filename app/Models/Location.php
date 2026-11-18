<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    // Izinkan nama dan deskripsi diisi dari form.
    protected $fillable = ['name', 'description'];

    // Menemukan saldo barang di lokasi ini.
    public function stocks()
    {
        return $this->hasMany(Stock::class);
    }

    // Menemukan pengaturan barang yang berlaku di lokasi ini.
    public function productLocations()
    {
        return $this->hasMany(ProductLocation::class);
    }

    public function purchaseReceiptItems()
    {
        return $this->hasMany(PurchaseReceiptItem::class);
    }

    public function saleItemSources()
    {
        return $this->hasMany(SaleItemSource::class);
    }

    // Menemukan transfer yang berangkat dari lokasi ini.
    public function stockTransfersFrom()
    {
        return $this->hasMany(StockTransfer::class, 'from_location_id');
    }

    public function stockTransfersTo()
    {
        return $this->hasMany(StockTransfer::class, 'to_location_id');
    }

    public function stockOpnames()
    {
        return $this->hasMany(StockOpname::class);
    }

    // Memeriksa apakah ada catatan transaksi yang masih memakai lokasi ini.
    public function hasTransactionHistory(): bool
    {
        return $this->purchaseReceiptItems()->exists()
            || $this->saleItemSources()->exists()
            || $this->stockTransfersFrom()->exists()
            || $this->stockTransfersTo()->exists()
            || $this->stockOpnames()->exists()
            || StockMovement::where('location_id', $this->id)->exists()
            || StockBatch::where('location_id', $this->id)->exists()
            || PurchaseInspection::where('quarantine_location_id', $this->id)->exists();
    }

    // Menentukan apakah ada barang dengan jumlah di atas nol.
    public function hasStock(): bool
    {
        return $this->stocks()->where('quantity', '>', 0)->exists();
    }
}
