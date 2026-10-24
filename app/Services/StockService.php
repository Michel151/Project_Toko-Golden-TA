<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Location;
use App\Models\PurchaseReceiptItem;
use App\Models\Stock;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StockService
{
    public function __construct(private MovingAverageCostService $costs) {}

    private function atomic(callable $work): mixed
    {
        return DB::transaction(function () use ($work) {
            $this->costs->lock();
            return $work();
        }, 3);
    }

    public function initializeImportedStock(Product $product, Location $location, float $baseQuantity, Model $reference, ?string $expiry = null, ?string $batchCode = null): void
    {
        $this->atomic(fn () => $this->initializeImportedStockInternal($product, $location, $baseQuantity, $reference, $expiry, $batchCode));
    }

    private function initializeImportedStockInternal(Product $product, Location $location, float $baseQuantity, Model $reference, ?string $expiry = null, ?string $batchCode = null): void
    {
        $this->costs->prepare($product);
        $balanceBefore = $this->increaseAggregateStock($product, $location, $baseQuantity);
        if ($product->is_expirable && $baseQuantity > 0) {
            StockBatch::create([
                'product_id'=>$product->id, 'location_id'=>$location->id,
                'received_date'=>now('Asia/Jakarta')->toDateString(), 'expired_date'=>$expiry,
                'batch_code'=>$batchCode, 'quantity_initial'=>$baseQuantity, 'quantity_remaining'=>$baseQuantity,
            ]);
        }
        if ($baseQuantity > 0) $this->recordMovement($product,$location,'initial_import',$baseQuantity,$balanceBefore,$reference,
            now('Asia/Jakarta')->toDateString(),'IMPORT-MULTI-'.$reference->getKey(),$batchCode ? 'Batch '.$batchCode : null);
        if ($baseQuantity > 0) {
            $unit = $product->defaultPurchaseUnit;
            $baseCost = $unit && (float) $unit->conversion_to_base > 0
                ? \Brick\Math\BigDecimal::of($unit->buy_price)->dividedBy($unit->conversion_to_base, 10, \Brick\Math\RoundingMode::HalfUp)
                : \Brick\Math\BigDecimal::of($product->buy_price);
            $this->costs->receive($product, $baseQuantity, MovingAverageCostService::money($baseCost->multipliedBy((string) $baseQuantity)), $reference, today(), 'import-movement:'.StockMovement::where('product_id', $product->id)->max('id'));
        }
    }

    /**
     * Tambah stok saat penerimaan barang.
     * quantity dalam satuan BELI.
     */
    public function receiveStock(Product $product, Location $location, float $quantity, ?Model $reference = null, $movementDate = null, ?string $referenceNumber = null, ?string $expiredDate = null, ?PurchaseReceiptItem $receiptItem = null): void
    {
        $this->atomic(fn () => $this->receiveStockInternal($product, $location, $quantity, $reference, $movementDate, $referenceNumber, $expiredDate, $receiptItem));
    }

    private function receiveStockInternal(Product $product, Location $location, float $quantity, ?Model $reference = null, $movementDate = null, ?string $referenceNumber = null, ?string $expiredDate = null, ?PurchaseReceiptItem $receiptItem = null): void
    {
        $this->costs->prepare($product);
        $stockQty = $this->toBaseQuantity($product, $quantity, $product->defaultPurchaseUnit);

        $balanceBefore = $this->increaseAggregateStock($product, $location, $stockQty);
        $this->createBatch($product, $location, $stockQty, $movementDate, $expiredDate, $receiptItem);

        $this->recordMovement($product, $location, 'purchase', $stockQty, $balanceBefore, $reference, $movementDate, $referenceNumber);
        $this->receiveValuation($product, $product->defaultPurchaseUnit, $quantity, $stockQty, $reference, $movementDate, $receiptItem);
    }

    public function receiveStockUsingUnit(Product $product, ProductUnit $productUnit, Location $location, float $quantity, ?Model $reference = null, $movementDate = null, ?string $referenceNumber = null, ?string $expiredDate = null, ?PurchaseReceiptItem $receiptItem = null): void
    {
        $this->atomic(fn () => $this->receiveStockUsingUnitInternal($product, $productUnit, $location, $quantity, $reference, $movementDate, $referenceNumber, $expiredDate, $receiptItem));
    }

    private function receiveStockUsingUnitInternal(Product $product, ProductUnit $productUnit, Location $location, float $quantity, ?Model $reference = null, $movementDate = null, ?string $referenceNumber = null, ?string $expiredDate = null, ?PurchaseReceiptItem $receiptItem = null): void
    {
        $this->costs->prepare($product);
        $stockQty = $this->toBaseQuantity($product, $quantity, $productUnit);

        $balanceBefore = $this->increaseAggregateStock($product, $location, $stockQty);
        $this->createBatch($product, $location, $stockQty, $movementDate, $expiredDate, $receiptItem);

        $this->recordMovement($product, $location, 'purchase', $stockQty, $balanceBefore, $reference, $movementDate, $referenceNumber);
        $this->receiveValuation($product, $productUnit, $quantity, $stockQty, $reference, $movementDate, $receiptItem);
    }

    private function receiveValuation(Product $product, ?ProductUnit $unit, float $quantity, float $baseQuantity, ?Model $reference, mixed $date, ?PurchaseReceiptItem $receiptItem): void
    {
        $orderItem = $receiptItem?->purchaseOrderItem;
        while ($orderItem && (float) $orderItem->buy_price <= 0 && $orderItem->source_purchase_order_item_id) {
            $orderItem = \App\Models\PurchaseOrderItem::findOrFail($orderItem->source_purchase_order_item_id);
        }
        $unitPrice = $orderItem?->buy_price ?? $unit?->buy_price ?? $product->buy_price;
        $value = MovingAverageCostService::money(\Brick\Math\BigDecimal::of($unitPrice)->multipliedBy((string) $quantity));
        if ($receiptItem) {
            $receiptItem->forceFill(['cost_base_quantity' => $baseQuantity, 'acquisition_value' => $value])->save();
        }
        $key = $receiptItem ? 'receipt:'.$receiptItem->id : 'receipt-movement:'.StockMovement::where('product_id', $product->id)->max('id');
        $this->costs->receive($product, $baseQuantity, $value, $reference, $date, $key);
    }

    /**
     * Kurangi stok saat penjualan.
     * quantity dalam satuan sesuai sale_type (grosir/ecer).
     */
    public function deductStock(Product $product, Location $location, float $quantity, string $saleType, ?Model $reference = null, $movementDate = null, ?string $referenceNumber = null): array
    {
        return $this->atomic(fn () => $this->deductStockInternal($product, $location, $quantity, $saleType, $reference, $movementDate, $referenceNumber));
    }

    private function deductStockInternal(Product $product, Location $location, float $quantity, string $saleType, ?Model $reference = null, $movementDate = null, ?string $referenceNumber = null): array
    {
        $this->costs->prepare($product);
        $stockQty = ($saleType === 'grosir' && $product->can_retail)
            ? $quantity * $product->conversion_factor
            : $quantity;

        $productUnit = is_numeric($saleType)
            ? ProductUnit::where('product_id', $product->id)->find((int) $saleType)
            : null;

        if ($productUnit) {
            $stockQty = $this->toBaseQuantity($product, $quantity, $productUnit);
        }

        $stock = Stock::where('product_id', $product->id)
                    ->where('location_id', $location->id)
                    ->lockForUpdate()
                    ->first();

        if (!$stock || $stock->quantity < $stockQty) {
            throw new \Exception("Stok tidak mencukupi di lokasi {$location->name} untuk produk {$product->name}.");
        }

        $batchDeductions = $product->is_expirable
            ? $this->deductSellableBatches($product, $location, $stockQty)
            : [];

        $balanceBefore = (float) $stock->quantity;
        $stock->quantity = $stock->quantity - $stockQty;
        $stock->save();

        $this->recordMovement($product, $location, 'sale', -$stockQty, $balanceBefore, $reference, $movementDate, $referenceNumber);

        return $batchDeductions;
    }

    public function restoreHeldSale(Sale $sale, string $movementType = 'sale_cancelled'): void
    {
        $this->atomic(fn () => $this->restoreHeldSaleInternal($sale, $movementType));
    }

    private function restoreHeldSaleInternal(Sale $sale, string $movementType = 'sale_cancelled'): void
    {
        $sale->load(['items.productUnit', 'items.product', 'items.sources']);
        app(SaleStockSnapshotService::class)->recoverLegacySources($sale);

        foreach ($sale->items as $item) {
            foreach ($item->sources as $source) {
                $quantity = (float) $source->base_quantity;
                $stock = Stock::where('product_id', $item->product_id)
                    ->where('location_id', $source->location_id)
                    ->lockForUpdate()->firstOrFail();
                $before = (float) $stock->quantity;
                $stock->quantity = $before + $quantity;
                $stock->save();

                if ($source->stock_batch_id) {
                    $batch = StockBatch::whereKey($source->stock_batch_id)->lockForUpdate()->firstOrFail();
                    $batch->quantity_remaining = (float) $batch->quantity_remaining + $quantity;
                    $batch->save();
                }

                StockMovement::create([
                    'product_id' => $item->product_id,
                    'location_id' => $source->location_id,
                    'movement_type' => $movementType,
                    'quantity' => $quantity,
                    'balance_before' => $before,
                    'balance_after' => $before + $quantity,
                    'movement_date' => today(),
                    'reference_type' => $sale->getMorphClass(),
                    'reference_id' => $sale->id,
                    'reference_number' => $sale->sale_number,
                    'created_by' => auth()->id(),
                ]);
            }
        }
    }

    /**
     * Pindah stok antar lokasi.
     * quantity dalam satuan yang tersimpan di stocks (ecer atau beli).
     */
    public function transferStock(Product $product, Location $from, Location $to, float $quantity, ?Model $reference = null, $movementDate = null, ?string $referenceNumber = null): void
    {
        DB::transaction(function () use ($product, $from, $to, $quantity, $reference, $movementDate, $referenceNumber) {
            $this->costs->lock();
            $this->costs->prepare($product);
            $stockFrom = Stock::where('product_id', $product->id)
                            ->where('location_id', $from->id)
                            ->lockForUpdate()
                            ->first();

            if (!$stockFrom || $stockFrom->quantity < $quantity) {
                throw new \Exception("Stok tidak mencukupi di {$from->name} untuk produk {$product->name}.");
            }

            $fromBalanceBefore = (float) $stockFrom->quantity;

            if ($product->is_expirable) {
                $this->transferBatches($product, $from, $to, $quantity, $movementDate);
            }

            $stockFrom->quantity = $stockFrom->quantity - $quantity;
            $stockFrom->save();

            $toBalanceBefore = $this->increaseAggregateStock($product, $to, $quantity);

            $this->recordMovement($product, $from, 'transfer_out', -$quantity, $fromBalanceBefore, $reference, $movementDate, $referenceNumber, "Transfer ke {$to->name}");
            $this->recordMovement($product, $to, 'transfer_in', $quantity, $toBalanceBefore, $reference, $movementDate, $referenceNumber, "Transfer dari {$from->name}");
        });
    }

    /**
     * Sesuaikan stok hasil stock opname.
     * physical_quantity dalam satuan yang tersimpan di stocks.
     */
    public function adjustStock(Product $product, Location $location, float $physicalQty, ?Model $reference = null, $movementDate = null, ?string $referenceNumber = null, ?string $valuationUnitCost = null): void
    {
        $this->atomic(fn () => $this->adjustStockInternal($product, $location, $physicalQty, $reference, $movementDate, $referenceNumber, $valuationUnitCost));
    }

    private function adjustStockInternal(Product $product, Location $location, float $physicalQty, ?Model $reference = null, $movementDate = null, ?string $referenceNumber = null, ?string $valuationUnitCost = null): void
    {
        $valuation = $this->costs->prepare($product);
        $stock = Stock::where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->lockForUpdate()
            ->first();
        $balanceBefore = (float) ($stock?->quantity ?? 0);

        Stock::updateOrCreate(
            ['product_id' => $product->id, 'location_id' => $location->id],
            ['quantity'   => $physicalQty]
        );

        $difference = $physicalQty - $balanceBefore;
        if ($product->is_expirable && abs($difference) > 0.00001) {
            $this->syncBatchesToPhysicalQuantity($product, $location, $physicalQty, $movementDate);
        }

        if (abs($difference) > 0.00001) {
            $this->recordMovement($product, $location, 'opname_adjustment', $difference, $balanceBefore, $reference, $movementDate, $referenceNumber);
            if (!$valuation) return;
            if ($difference > 0 && $reference === null && str_starts_with($referenceNumber ?? '', 'IMPORT')) {
                if (!auth()->user()?->isOwner() || $valuationUnitCost === null) throw \Illuminate\Validation\ValidationException::withMessages(['costing' => 'Nilai perolehan stok impor wajib disediakan owner.']);
                $this->costs->receive($product, $difference, MovingAverageCostService::money(\Brick\Math\BigDecimal::of($valuationUnitCost)->multipliedBy((string) $difference)), null, $movementDate, 'legacy-import-movement:'.StockMovement::where('product_id', $product->id)->max('id'));
            } else {
                $this->costs->adjustment($product, $difference, $valuationUnitCost, $reference, $movementDate, 'adjustment-movement:'.StockMovement::where('product_id', $product->id)->max('id'));
            }
        }
    }

    public function sellableStockAt(Product $product, Location|int $location): float
    {
        $locationId = $location instanceof Location ? $location->id : $location;

        if (! $product->is_expirable) {
            return (float) Stock::where('product_id', $product->id)
                ->where('location_id', $locationId)
                ->value('quantity');
        }

        return (float) StockBatch::where('product_id', $product->id)
            ->where('location_id', $locationId)
            ->sellable()
            ->sum('quantity_remaining');
    }

    private function recordMovement(
        Product $product,
        Location $location,
        string $type,
        float $quantity,
        float $balanceBefore,
        ?Model $reference = null,
        $movementDate = null,
        ?string $referenceNumber = null,
        ?string $notes = null
    ): void {
        StockMovement::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceBefore + $quantity,
            'movement_date' => $movementDate ?? today(),
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'reference_number' => $referenceNumber,
            'notes' => $notes,
            'created_by' => auth()->id(),
        ]);
    }

    private function increaseAggregateStock(Product $product, Location $location, float $stockQty): float
    {
        $stock = Stock::where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->lockForUpdate()
            ->first();

        $balanceBefore = (float) ($stock?->quantity ?? 0);

        if ($stock) {
            $stock->quantity = $stock->quantity + $stockQty;
            $stock->save();
        } else {
            Stock::create([
                'product_id'  => $product->id,
                'location_id' => $location->id,
                'quantity'    => $stockQty,
            ]);
        }

        return $balanceBefore;
    }

    private function createBatch(Product $product, Location $location, float $stockQty, $movementDate, ?string $expiredDate, ?PurchaseReceiptItem $receiptItem): void
    {
        if (! $product->is_expirable) {
            return;
        }

        StockBatch::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'purchase_receipt_item_id' => $receiptItem?->id,
            'received_date' => $movementDate ?? today(),
            'expired_date' => $expiredDate,
            'quantity_initial' => $stockQty,
            'quantity_remaining' => $stockQty,
        ]);
    }

    private function deductSellableBatches(Product $product, Location $location, float $stockQty): array
    {
        $remaining = $stockQty;
        $deductions = [];

        $batches = StockBatch::where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->sellable()
            ->lockForUpdate()
            ->orderByRaw('expired_date IS NULL')
            ->orderBy('expired_date')
            ->orderBy('received_date')
            ->orderBy('id')
            ->get();

        foreach ($batches as $batch) {
            if ($remaining <= 0.00001) {
                break;
            }

            $available = (float) $batch->quantity_remaining;
            $used = min($available, $remaining);
            $batch->quantity_remaining = $available - $used;
            $batch->save();
            $deductions[] = [
                'stock_batch_id' => $batch->id,
                'quantity' => $used,
            ];
            $remaining -= $used;
        }

        if ($remaining > 0.00001) {
            throw new \Exception("Stok layak jual tidak mencukupi di lokasi {$location->name} untuk produk {$product->name}. Periksa barang kedaluwarsa atau batch stok.");
        }

        return $deductions;
    }

    private function transferBatches(Product $product, Location $from, Location $to, float $quantity, $movementDate): void
    {
        $remaining = $quantity;

        $batches = StockBatch::where('product_id', $product->id)
            ->where('location_id', $from->id)
            ->sellable()
            ->lockForUpdate()
            ->orderByRaw('expired_date IS NULL')
            ->orderBy('expired_date')
            ->orderBy('received_date')
            ->orderBy('id')
            ->get();

        foreach ($batches as $batch) {
            if ($remaining <= 0.00001) {
                break;
            }

            $available = (float) $batch->quantity_remaining;
            $used = min($available, $remaining);
            $batch->quantity_remaining = $available - $used;
            $batch->save();

            StockBatch::create([
                'product_id' => $product->id,
                'location_id' => $to->id,
                'purchase_receipt_item_id' => $batch->purchase_receipt_item_id,
                'received_date' => $movementDate ?? today(),
                'expired_date' => $batch->expired_date,
                'batch_code' => $batch->batch_code,
                'quantity_initial' => $used,
                'quantity_remaining' => $used,
            ]);

            $remaining -= $used;
        }

        if ($remaining > 0.00001) {
            throw new \Exception("Stok layak transfer tidak mencukupi di {$from->name} untuk produk {$product->name}. Periksa barang kedaluwarsa atau batch stok.");
        }
    }

    /**
     * Konversi quantity dari satuan beli ke satuan ecer (untuk display).
     */
    public function toRetailQty(Product $product, float $buyQty): float
    {
        return $product->can_retail ? $buyQty * $product->conversion_factor : $buyQty;
    }

    public function toBaseQuantity(Product $product, float $quantity, ?ProductUnit $productUnit = null): float
    {
        if ($productUnit) {
            return $quantity * (float) $productUnit->conversion_to_base;
        }

        return $product->can_retail ? $quantity * (float) $product->conversion_factor : $quantity;
    }

    public function fromBaseQuantity(float $baseQuantity, ProductUnit $productUnit): float
    {
        $conversion = (float) $productUnit->conversion_to_base;
        return $conversion > 0 ? $baseQuantity / $conversion : $baseQuantity;
    }

    /**
     * Konversi quantity dari satuan ecer ke satuan beli (untuk display).
     */
    public function toBuyQty(Product $product, float $retailQty): float
    {
        if ($product->can_retail && $product->conversion_factor > 0) {
            return $retailQty / $product->conversion_factor;
        }
        return $retailQty;
    }

    private function syncBatchesToPhysicalQuantity(Product $product, Location $location, float $physicalQty, $movementDate): void
    {
        $currentBatchQty = (float) StockBatch::where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->lockForUpdate()
            ->sum('quantity_remaining');

        $difference = $physicalQty - $currentBatchQty;
        if (abs($difference) <= 0.00001) {
            return;
        }

        if ($difference > 0) {
            StockBatch::create([
                'product_id' => $product->id,
                'location_id' => $location->id,
                'received_date' => $movementDate ?? today(),
                'expired_date' => null,
                'quantity_initial' => $difference,
                'quantity_remaining' => $difference,
            ]);
            return;
        }

        $remainingReduction = abs($difference);
        $batches = StockBatch::where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->where('quantity_remaining', '>', 0)
            ->lockForUpdate()
            ->orderByRaw('expired_date IS NULL')
            ->orderByDesc('expired_date')
            ->orderByDesc('received_date')
            ->orderByDesc('id')
            ->get();

        foreach ($batches as $batch) {
            if ($remainingReduction <= 0.00001) {
                break;
            }

            $available = (float) $batch->quantity_remaining;
            $reduced = min($available, $remainingReduction);
            $batch->quantity_remaining = $available - $reduced;
            $batch->save();
            $remainingReduction -= $reduced;
        }
    }
}
