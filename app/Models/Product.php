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

    public function costBalance() { return $this->hasOne(InventoryCostBalance::class); }

    public function inventoryBaseCost(): float
    {
        if ($this->costBalance?->activated_at) return (float) $this->costBalance->average_cost;
        $unit = $this->productUnits->firstWhere('is_default_purchase', true);
        return $unit && (float) $unit->conversion_to_base > 0 ? (float) $unit->buy_price / (float) $unit->conversion_to_base : (float) $this->buy_price;
    }

    public function inventoryValue(?int $locationId = null): float
    {
        if ($this->costBalance?->activated_at) {
            if ($locationId === null) return (float) $this->costBalance->inventory_value;
            return round((float) app(\App\Services\MovingAverageCostService::class)->ownedQuantity($this->id, $locationId) * $this->inventoryBaseCost(), 2);
        }
        $quantity = $locationId === null ? $this->stocks->sum('quantity') : ($this->stocks->firstWhere('location_id', $locationId)?->quantity ?? 0);
        return (float) $quantity * $this->inventoryBaseCost();
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

    public function isExpirySettingLocked(): bool
    {
        return $this->stocks()->where('quantity', '!=', 0)->exists()
            || $this->hasTransactionHistory();
    }

    public function hasTransactionHistory(): bool
    {
        return $this->stockBatches()->exists()
            || $this->saleItems()->exists()
            || $this->purchaseOrderItems()->exists()
            || PurchaseReceiptItem::where('product_id', $this->id)->exists()
            || $this->stockMovements()->exists()
            || $this->stockTransfers()->exists()
            || StockTransfer::where('product_id', $this->id)->exists()
            || StockOpnameItem::where('product_id', $this->id)->exists();
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

            // Match the four decimal places stored in stock quantities and avoid
            // floating-point remainders dropping a fractional base unit.
            $remaining = (int) round(abs($quantity) * 10000);
            $parts = [];

            foreach ($units as $index => $productUnit) {
                $conversion = (int) round((float) $productUnit->conversion_to_base * 10000);
                if ($conversion <= 0) {
                    continue;
                }

                // Keep the fractional remainder in the smallest unit rather
                // than flooring it to zero after decomposing larger packages.
                $amount = $index === $units->count() - 1
                    ? $remaining / $conversion
                    : intdiv($remaining, $conversion);

                if ($amount > 0) {
                    $parts[] = \App\Support\DisplayNumber::quantity($amount) . ' ' . $productUnit->unit->name;
                    $remaining %= $conversion;
                }
            }

            if ($parts) {
                $text = implode(' ', $parts);
                return round($quantity, 4) < 0
                    ? '-' . (count($parts) > 1 ? '(' . $text . ')' : $text)
                    : $text;
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
