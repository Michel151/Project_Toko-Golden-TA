<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Location;
use App\Models\PurchaseReceiptItem;
use App\Models\Stock;
use App\Models\StockBatch;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * Tambah stok saat penerimaan barang.
     * quantity dalam satuan BELI.
     */
    public function receiveStock(Product $product, Location $location, float $quantity, ?Model $reference = null, $movementDate = null, ?string $referenceNumber = null, ?string $expiredDate = null, ?PurchaseReceiptItem $receiptItem = null): void
    {
        $stockQty = $this->toBaseQuantity($product, $quantity, $product->defaultPurchaseUnit);

        $balanceBefore = $this->increaseAggregateStock($product, $location, $stockQty);
        $this->createBatch($product, $location, $stockQty, $movementDate, $expiredDate, $receiptItem);

        $this->recordMovement($product, $location, 'purchase', $stockQty, $balanceBefore, $reference, $movementDate, $referenceNumber);
    }

    public function receiveStockUsingUnit(Product $product, ProductUnit $productUnit, Location $location, float $quantity, ?Model $reference = null, $movementDate = null, ?string $referenceNumber = null, ?string $expiredDate = null, ?PurchaseReceiptItem $receiptItem = null): void
    {
        $stockQty = $this->toBaseQuantity($product, $quantity, $productUnit);

        $balanceBefore = $this->increaseAggregateStock($product, $location, $stockQty);
        $this->createBatch($product, $location, $stockQty, $movementDate, $expiredDate, $receiptItem);

        $this->recordMovement($product, $location, 'purchase', $stockQty, $balanceBefore, $reference, $movementDate, $referenceNumber);
    }

    /**
     * Kurangi stok saat penjualan.
     * quantity dalam satuan sesuai sale_type (grosir/ecer).
     */
    public function deductStock(Product $product, Location $location, float $quantity, string $saleType, ?Model $reference = null, $movementDate = null, ?string $referenceNumber = null): void
    {
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

        if ($product->is_expirable) {
            $this->deductSellableBatches($product, $location, $stockQty);
        }

        $balanceBefore = (float) $stock->quantity;
        $stock->quantity = $stock->quantity - $stockQty;
        $stock->save();

        $this->recordMovement($product, $location, 'sale', -$stockQty, $balanceBefore, $reference, $movementDate, $referenceNumber);
    }

    /**
     * Pindah stok antar lokasi.
     * quantity dalam satuan yang tersimpan di stocks (ecer atau beli).
     */
    public function transferStock(Product $product, Location $from, Location $to, float $quantity, ?Model $reference = null, $movementDate = null, ?string $referenceNumber = null): void
    {
        DB::transaction(function () use ($product, $from, $to, $quantity, $reference, $movementDate, $referenceNumber) {
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

            $stockTo = Stock::where('product_id', $product->id)
                            ->where('location_id', $to->id)
                            ->first();

            $toBalanceBefore = (float) ($stockTo?->quantity ?? 0);
            if ($stockTo) {
                $stockTo->quantity = $stockTo->quantity + $quantity;
                $stockTo->save();
            } else {
                Stock::create([
                    'product_id'  => $product->id,
                    'location_id' => $to->id,
                    'quantity'    => $quantity,
                ]);
            }

            $this->recordMovement($product, $from, 'transfer_out', -$quantity, $fromBalanceBefore, $reference, $movementDate, $referenceNumber, "Transfer ke {$to->name}");
            $this->recordMovement($product, $to, 'transfer_in', $quantity, $toBalanceBefore, $reference, $movementDate, $referenceNumber, "Transfer dari {$from->name}");
        });
    }

    /**
     * Sesuaikan stok hasil stock opname.
     * physical_quantity dalam satuan yang tersimpan di stocks.
     */
    public function adjustStock(Product $product, Location $location, float $physicalQty, ?Model $reference = null, $movementDate = null, ?string $referenceNumber = null): void
    {
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
        if (abs($difference) > 0.00001) {
            $this->recordMovement($product, $location, 'opname_adjustment', $difference, $balanceBefore, $reference, $movementDate, $referenceNumber);
        }
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

    private function deductSellableBatches(Product $product, Location $location, float $stockQty): void
    {
        $remaining = $stockQty;

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
            $remaining -= $used;
        }

        if ($remaining > 0.00001) {
            throw new \Exception("Stok layak jual tidak mencukupi di lokasi {$location->name} untuk produk {$product->name}. Periksa barang kadaluarsa atau batch stok.");
        }
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
                'quantity_initial' => $used,
                'quantity_remaining' => $used,
            ]);

            $remaining -= $used;
        }

        if ($remaining > 0.00001) {
            throw new \Exception("Stok layak transfer tidak mencukupi di {$from->name} untuk produk {$product->name}. Periksa barang kadaluarsa atau batch stok.");
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
}
