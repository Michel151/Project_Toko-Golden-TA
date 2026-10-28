<?php

namespace App\Services;

use App\Models\{Product, PurchaseOrderItem, PurchaseReceipt};
use Brick\Math\{BigDecimal, RoundingMode};
use Illuminate\Validation\ValidationException;

class PurchasePriceService
{
    public function supplierProduct(Product $product): array
    {
        $purchase = $product->productUnits->firstWhere('is_default_purchase', true);
        $conversion = $purchase?->conversion_to_base ?? ($product->can_retail ? $product->conversion_factor : 1);
        $supplierPrice = $product->pivot?->buy_price;
        $price = $supplierPrice ?? $purchase?->buy_price ?? $product->buy_price;
        $hasSupplierPrice = $supplierPrice !== null;
        return [
            'id'=>$product->id, 'name'=>$product->name, 'barcode'=>$product->barcode,
            'unit'=>$purchase?->unit?->name ?? $product->buyUnit?->name,
            'price'=>(float) $price, 'price_source'=>$hasSupplierPrice ? 'supplier' : 'master',
            'units'=>$product->productUnits->sortByDesc('conversion_to_base')->map(fn ($unit) => [
                'id'=>$unit->id, 'name'=>$unit->unit->name,
                'buy_price'=>$hasSupplierPrice ? (float) $this->convert($price, $conversion, $unit->conversion_to_base) : (float) $unit->buy_price,
                'is_default_purchase'=>$unit->is_default_purchase,
            ])->values()->all(),
        ];
    }

    public function updateMaster(PurchaseOrderItem $item, PurchaseReceipt $receipt): void
    {
        $product = Product::whereKey($item->product_id)->lockForUpdate()->firstOrFail();
        $latest = PurchaseReceipt::whereHas('items', fn ($query) => $query->where('product_id', $product->id)->where('quantity_received', '>', 0))
            ->whereHas('purchaseOrder', fn ($query) => $query->whereNull('root_purchase_order_id'))
            ->latest('receipt_date')->latest('id')->value('id');
        if ((int) $latest !== $receipt->id) return;
        $units = $product->productUnits()->orderBy('id')->lockForUpdate()->get();
        $receivedUnit = $units->firstWhere('id', $item->product_unit_id);
        if (!$receivedUnit) throw ValidationException::withMessages(['items'=>'Satuan penerimaan tidak lagi terdaftar pada barang.']);
        $purchase = $units->firstWhere('is_default_purchase', true) ?? $units->firstWhere('unit_id', $product->buy_unit_id);
        if (!$purchase) throw ValidationException::withMessages(['items'=>'Satuan pembelian default barang tidak valid.']);
        $prices = [];
        foreach ($units as $unit) $prices[$unit->id] = $this->convert($item->buy_price, $receivedUnit->conversion_to_base, $unit->conversion_to_base);
        foreach ($units as $unit) $unit->update(['buy_price'=>$prices[$unit->id]]);
        $product->update(['buy_price'=>$prices[$purchase->id]]);
    }

    private function convert(string|float|int $price, string|float|int $from, string|float|int $to): string
    {
        if ((float) $from <= 0 || (float) $to <= 0) throw ValidationException::withMessages(['items'=>'Konversi satuan barang harus lebih besar dari nol.']);
        $result = BigDecimal::of((string) $price)->multipliedBy((string) $to)->dividedBy((string) $from, 2, RoundingMode::HalfUp);
        if ($result->isLessThan(0) || $result->isGreaterThan('9999999999999.99')) throw ValidationException::withMessages(['items'=>'Harga hasil konversi melebihi batas penyimpanan.']);
        return (string) $result;
    }
}
