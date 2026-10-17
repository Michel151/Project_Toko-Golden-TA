<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;

class NotificationService
{
    /**
     * Cek notifikasi stok minimum untuk semua produk.
     * Return array of notification objects.
     */
    public function checkAllProducts(): array
    {
        $notifications = [];

        Product::active()->with(['productLocations.location', 'stocks', 'stockBatches.location', 'buyUnit', 'retailUnit', 'productUnits.unit'])->each(function ($product) use (&$notifications) {
            $notifications = array_merge($notifications, $this->checkProduct($product));
        });

        return $notifications;
    }

    /**
     * Cek notifikasi stok minimum untuk satu produk.
     */
    public function checkProduct(Product $product): array
    {
        $notifications = array_values(array_filter([
            $this->checkStock($product),
            ...$this->checkExpiringBatches($product),
        ]));

        if ($notifications === []) {
            return [];
        }

        return [$this->mergeProductNotifications($product, $notifications)];
    }

    private function checkStock(Product $product): ?array
    {
        $affectedLocations = [];

        // Cek per lokasi
        foreach ($product->productLocations as $pl) {
            $minStock = (float) $pl->min_stock;
            $currentQty = $this->availableQuantityAt($product, (int) $pl->location_id);

            if ($currentQty <= $minStock) {
                // Ada stok di lokasi lain? → sarankan transfer, bukan order
                $affectedLocations[] = [
                    'name' => $pl->location->name,
                    'has_stock_elsewhere' => $this->hasAvailableStockOutside($product, (int) $pl->location_id),
                ];
            }
        }

        $totalStock = $this->availableQuantity($product);
        $minStockTotal = (float) $product->min_stock_total;
        $isTotalLow = $minStockTotal > 0 && $totalStock <= $minStockTotal;

        if ($affectedLocations === [] && ! $isTotalLow) {
            return null;
        }

        $locationNames = array_column($affectedLocations, 'name');
        $needsOrder = $isTotalLow || collect($affectedLocations)->contains(
            fn (array $location) => ! $location['has_stock_elsewhere']
        );
        $locationList = $this->formatList($locationNames);

        if ($locationNames !== [] && $isTotalLow) {
            $message = "Stok {$product->name} menipis di {$locationList}. Total stok semua lokasi sangat rendah. Segera order ke supplier.";
        } elseif ($isTotalLow) {
            $message = "Total stok {$product->name} sangat rendah. Segera order ke supplier.";
        } elseif ($needsOrder) {
            $message = "Stok {$product->name} menipis di {$locationList}. Perlu order ke supplier.";
        } else {
            $message = "Stok {$product->name} menipis di {$locationList}. Pertimbangkan transfer dari lokasi lain.";
        }

        return [
            'type'           => $needsOrder ? 'order' : 'transfer',
            'level'          => $needsOrder ? 'danger' : 'warning',
            'badge_label'    => $needsOrder ? 'Order' : 'Transfer',
            'badge_class'    => $needsOrder ? 'danger' : 'warning text-dark',
            'product_id'     => $product->id,
            'product_name'   => $product->name,
            'location_name'  => count($locationNames) > 1 ? count($locationNames) . ' lokasi' : ($locationNames[0] ?? 'Semua Lokasi'),
            'location_names' => $locationNames,
            'current_stock'  => $totalStock,
            'min_stock'      => $isTotalLow ? $minStockTotal : null,
            'message'        => $message,
        ];
    }

    /**
     * Hitung jumlah notifikasi (untuk badge navbar).
     */
    public function countNotifications(): int
    {
        return count($this->checkAllProducts());
    }

    private function availableQuantityAt(Product $product, int $locationId): float
    {
        if (! $product->is_expirable) {
            return (float) ($product->stocks->where('location_id', $locationId)->first()?->quantity ?? 0);
        }

        return (float) $this->sellableBatches($product)
            ->where('location_id', $locationId)
            ->sum(fn ($batch) => (float) $batch->quantity_remaining);
    }

    private function availableQuantity(Product $product): float
    {
        if (! $product->is_expirable) {
            return (float) $product->stocks->sum('quantity');
        }

        return (float) $this->sellableBatches($product)
            ->sum(fn ($batch) => (float) $batch->quantity_remaining);
    }

    private function hasAvailableStockOutside(Product $product, int $locationId): bool
    {
        if (! $product->is_expirable) {
            return $product->stocks
                ->where('location_id', '!=', $locationId)
                ->where('quantity', '>', 0)
                ->isNotEmpty();
        }

        return $this->sellableBatches($product)
            ->where('location_id', '!=', $locationId)
            ->contains(fn ($batch) => (float) $batch->quantity_remaining > 0);
    }

    private function checkExpiringBatches(Product $product): array
    {
        if (! $product->is_expirable) {
            return [];
        }

        return $product->stockBatches
            ->filter(fn ($batch) =>
                (float) $batch->quantity_remaining > 0
                && $batch->expired_date
                && $batch->expired_date->lte(today()->addDays(30))
            )
            ->sortBy('expired_date')
            ->map(function ($batch) use ($product) {
                $isExpired = $batch->expired_date->lt(today());
                $isToday = $batch->expired_date->isSameDay(today());
                $date = $batch->expired_date->format('d/m/Y');
                $quantity = $product->displayStock((float) $batch->quantity_remaining);
                $locationName = $batch->location?->name ?? 'Tanpa Lokasi';

                $expiryText = $isExpired
                    ? "sudah kedaluwarsa sejak {$date}"
                    : ($isToday ? 'kedaluwarsa hari ini' : "akan kedaluwarsa pada {$date}");

                return [
                    'type'          => 'expiry',
                    'level'         => $isExpired ? 'danger' : 'warning',
                    'badge_label'   => $isExpired ? 'Kedaluwarsa' : 'Hampir Kedaluwarsa',
                    'badge_class'   => $isExpired ? 'expired' : 'warning text-dark',
                    'product_id'    => $product->id,
                    'product_name'  => $product->name,
                    'location_name' => $locationName,
                    'location_names' => [$locationName],
                    'current_stock' => (float) $batch->quantity_remaining,
                    'min_stock'     => null,
                    'expired_date'  => $batch->expired_date->toDateString(),
                    'message'       => "Stok {$product->name} di {$locationName} {$expiryText}. Sisa {$quantity}.",
                ];
            })
            ->values()
            ->all();
    }

    private function mergeProductNotifications(Product $product, array $notifications): array
    {
        $badges = collect($notifications)
            ->map(fn (array $notification) => [
                'label' => $notification['badge_label'],
                'class' => $notification['badge_class'],
            ])
            ->unique(fn (array $badge) => $badge['label'] . '|' . $badge['class'])
            ->values()
            ->all();
        $locationNames = collect($notifications)
            ->flatMap(fn (array $notification) => $notification['location_names'] ?? [$notification['location_name']])
            ->filter(fn ($locationName) => $locationName !== 'Semua Lokasi')
            ->unique()
            ->values()
            ->all();
        $types = collect($notifications)->pluck('type')->unique()->values();
        $primary = $notifications[0];

        return array_merge($primary, [
            'type' => $types->count() === 1 ? $types->first() : 'combined',
            'level' => collect($notifications)->contains(
                fn (array $notification) => $notification['level'] === 'danger'
            ) ? 'danger' : 'warning',
            'product_id' => $product->id,
            'product_name' => $product->name,
            'location_name' => count($locationNames) > 1
                ? count($locationNames) . ' lokasi'
                : ($locationNames[0] ?? 'Semua Lokasi'),
            'location_names' => $locationNames,
            'badges' => $badges,
            'message' => implode(' ', array_column($notifications, 'message')),
        ]);
    }

    private function formatList(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        $lastItem = array_pop($items);

        return implode(', ', $items) . ', dan ' . $lastItem;
    }

    private function sellableBatches(Product $product): Collection
    {
        return $product->stockBatches->filter(fn ($batch) =>
            (float) $batch->quantity_remaining > 0
            && (! $batch->expired_date || $batch->expired_date->gte(today()))
        );
    }
}
