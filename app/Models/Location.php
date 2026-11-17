<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    // Nama dan deskripsi berasal dari form lokasi.
    protected $fillable = ['name', 'description'];

    // Hubungkan baris stok ke lokasi melalui stocks.location_id.
    public function stocks()
    {
        return $this->hasMany(Stock::class);
    }

    // Ambil pengaturan produk per lokasi, termasuk batas minimum stok.
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

    // Lokasi asal dan tujuan memakai dua foreign key berbeda pada tabel transfer.
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

    // Cari rujukan dari penerimaan, penjualan, transfer, opname, mutasi, batch, dan karantina.
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

    // Cek apakah lokasi masih memiliki stok > 0
    // Contoh: Roti 20 berarti masih ada stok. Baris Roti dengan jumlah 0 tidak membuat hasStock() bernilai true.
    public function hasStock(): bool
    {
        return $this->stocks()->where('quantity', '>', 0)->exists();
    }
}
