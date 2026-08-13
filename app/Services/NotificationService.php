<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Stock;

class NotificationService
{
    /**
     * Cek notifikasi stok minimum untuk semua produk.
     * Return array of notification objects.
     */
    public function checkAllProducts(): array
    {
        $notifications = [];

        Product::with(['productLocations.location', 'stocks'])->each(function ($product) use (&$notifications) {
            $notifications = array_merge($notifications, $this->checkProduct($product));
        });

        return $notifications;
    }

    /**
     * Cek notifikasi stok minimum untuk satu produk.
     */
    public function checkProduct(Product $product): array
    {
        $notifications = [];

        // Cek per lokasi
        foreach ($product->productLocations as $pl) {
            $minStock = (float) $pl->min_stock;
            $stock = $product->stocks->where('location_id', $pl->location_id)->first();
            $currentQty = $stock ? (float) $stock->quantity : 0;

            if ($currentQty <= $minStock) {
                // Ada stok di lokasi lain? → sarankan transfer, bukan order
                $hasStockElsewhere = $product->stocks
                    ->where('location_id', '!=', $pl->location_id)
                    ->where('quantity', '>', 0)
                    ->isNotEmpty();

                $notifications[] = [
                    'type'          => $hasStockElsewhere ? 'transfer' : 'order',
                    'level'         => 'warning',
                    'product_id'    => $product->id,
                    'product_name'  => $product->name,
                    'location_name' => $pl->location->name,
                    'current_stock' => $currentQty,
                    'min_stock'     => $minStock,
                    'message'       => $hasStockElsewhere
                        ? "Stok {$product->name} di {$pl->location->name} menipis. Pertimbangkan transfer dari gudang lain."
                        : "Stok {$product->name} di {$pl->location->name} menipis. Perlu order ke supplier.",
                ];
            }
        }

        // Cek total stok semua lokasi
        if ((float) $product->min_stock_total <= 0) {
            return $notifications;
        }

        $totalStock = (float) $product->stocks->sum('quantity');
        if ($totalStock <= (float) $product->min_stock_total) {
            $notifications[] = [
                'type'          => 'order',
                'level'         => 'danger',
                'product_id'    => $product->id,
                'product_name'  => $product->name,
                'location_name' => 'Semua Lokasi',
                'current_stock' => $totalStock,
                'min_stock'     => (float) $product->min_stock_total,
                'message'       => "Total stok {$product->name} sangat rendah! Segera order ke supplier.",
            ];
        }

        return $notifications;
    }

    /**
     * Hitung jumlah notifikasi (untuk badge navbar).
     */
    public function countNotifications(): int
    {
        return count($this->checkAllProducts());
    }
}
