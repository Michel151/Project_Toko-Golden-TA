<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    protected $fillable = ['name', 'description'];

    public function stocks()
    {
        return $this->hasMany(Stock::class);
    }

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

    // Cek apakah lokasi masih memiliki stok > 0
    public function hasStock(): bool
    {
        return $this->stocks()->where('quantity', '>', 0)->exists();
    }
}
