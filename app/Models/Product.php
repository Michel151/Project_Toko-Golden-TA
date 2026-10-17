<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'name',
        'barcode',
        'is_expirable',
        'is_active',
        'category_id',
        'buy_unit_id',
        'buy_price',
        'sell_price_wholesale',
        'can_retail',
        'retail_unit_id',
        'conversion_factor',
        'sell_price_retail',
        'min_stock_total',
    ];

    protected $casts = [
        'can_retail'           => 'boolean',
        'is_expirable'         => 'boolean',
        'is_active'            => 'boolean',
        'buy_price'            => 'decimal:2',
        'sell_price_wholesale' => 'decimal:2',
        'sell_price_retail'    => 'decimal:2',
        'conversion_factor'    => 'decimal:4',
        'min_stock_total'      => 'decimal:4',
    ];

    // Relationships
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function buyUnit()
    {
        return $this->belongsTo(Unit::class, 'buy_unit_id');
    }

    public function retailUnit()
    {
        return $this->belongsTo(Unit::class, 'retail_unit_id');
    }

    public function productUnits()
    {
        return $this->hasMany(ProductUnit::class);
    }

    public function baseProductUnit()
    {
        return $this->hasOne(ProductUnit::class)->where('is_base_unit', true);
    }

    public function defaultPurchaseUnit()
    {
        return $this->hasOne(ProductUnit::class)->where('is_default_purchase', true);
    }

    public function defaultSaleUnit()
    {
        return $this->hasOne(ProductUnit::class)->where('is_default_sale', true);
    }

    public function stocks()
    {
        return $this->hasMany(Stock::class);
    }

    public function stockBatches()
    {
        return $this->hasMany(StockBatch::class);
    }

    public function sellableStockBatches()
    {
        return $this->hasMany(StockBatch::class)->sellable();
    }

    public function suppliers()
    {
        return $this->belongsToMany(Supplier::class)
            ->withPivot('buy_price')
            ->withTimestamps();
    }

    public function productLocations()
    {
        return $this->hasMany(ProductLocation::class);
    }

    public function purchaseOrderItems()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function stockTransfers()
    {
        return $this->belongsToMany(StockTransfer::class, 'stock_transfer_items');
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Helper: total stok semua lokasi
    public function stockAvailability(?int $locationId = null): array
    {
        $stocks = $this->stocks->when($locationId !== null, fn ($rows) => $rows->where('location_id', $locationId));
        $batches = $this->stockBatches
            ->when($locationId !== null, fn ($rows) => $rows->where('location_id', $locationId))
            ->filter(fn ($batch) => (float) $batch->quantity_remaining > 0);
        $physical = (float) $stocks->sum('quantity');
        $expired = $this->is_expirable
            ? (float) $batches->filter(fn ($batch) => $batch->expired_date && $batch->expired_date->lt(today()))->sum('quantity_remaining') : 0.0;
        // Match the sale engine: undated tracked batches are currently sellable.
        $sellable = $this->is_expirable ? (float) $batches
            ->filter(fn ($batch) => ! $batch->expired_date || $batch->expired_date->gte(today()))->sum('quantity_remaining') : $physical;
        return compact('physical', 'sellable', 'expired');
    }

    public function totalStock(): float
    {
        return (float) $this->stocks()->sum('quantity');
    }

    // Helper: stok di lokasi tertentu
    public function stockAt(int $locationId): float
    {
        $stock = $this->stocks()->where('location_id', $locationId)->first();
        return $stock ? (float) $stock->quantity : 0;
    }

    // Helper: tampilkan stok dalam satuan yang mudah dibaca
    public function displayStock(float $quantity): string
    {
        if ($this->relationLoaded('productUnits') && $this->productUnits->isNotEmpty()) {
            $units = $this->productUnits
                ->loadMissing('unit')
                ->sortByDesc(fn ($productUnit) => (float) $productUnit->conversion_to_base)
                ->values();

            $remaining = $quantity;
            $parts = [];

            foreach ($units as $productUnit) {
                $conversion = (float) $productUnit->conversion_to_base;
                if ($conversion <= 0) {
                    continue;
                }

                $amount = floor($remaining / $conversion);
                if ($amount <= 0 && $parts) {
                    continue;
                }

                if ($amount > 0) {
                    $parts[] = number_format($amount, 0, ',', '.') . ' ' . $productUnit->unit->name;
                    $remaining = fmod($remaining, $conversion);
                }
            }

            if ($parts) {
                return implode(' ', $parts);
            }

            $baseUnit = $units->last();
            return '0 ' . ($baseUnit?->unit?->name ?? '');
        }

        if ($this->can_retail && $this->conversion_factor > 0) {
            $wholesale = floor($quantity / $this->conversion_factor);
            $retail    = fmod($quantity, $this->conversion_factor);
            if ($retail > 0) {
                return "{$wholesale} {$this->buyUnit->name} {$retail} {$this->retailUnit->name}";
            }
            return "{$wholesale} {$this->buyUnit->name}";
        }
        return "{$quantity} {$this->buyUnit->name}";
    }

    // Cek apakah total stok di bawah minimum
    public function isBelowMinStock(): bool
    {
        return $this->totalStock() <= $this->min_stock_total;
    }
}
