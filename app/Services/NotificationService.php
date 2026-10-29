<?php

namespace App\Services;

use App\Models\Product;

class NotificationService
{
    public const FILTERS = ['expired' => 'Sudah kedaluwarsa', 'out_of_stock' => 'Stok habis', 'expiry_today' => 'Kedaluwarsa hari ini', 'expiring' => 'Mendekati kedaluwarsa', 'low_stock' => 'Stok rendah'];

    // Share a snapshot within this request only, never across requests.
    public function forRequest(): array
    {
        if (! request()->attributes->has('inventory.notifications')) {
            request()->attributes->set('inventory.notifications', $this->checkAllProducts());
        }

        return request()->attributes->get('inventory.notifications');
    }

    public function checkAllProducts(): array
    {
        $notifications = [];
        Product::active()->with([
            'productLocations.location', 'stocks.location', 'stockBatches.location', 'buyUnit', 'retailUnit', 'productUnits.unit',
            'purchaseOrderItems' => fn ($q) => $q->whereHas('purchaseOrder', fn ($orders) => $orders->whereIn('status', ['pending', 'partial'])),
            'purchaseOrderItems.productUnit', 'purchaseOrderItems.purchaseOrder',
        ])->each(function ($product) use (&$notifications) {
            foreach ($this->checkProduct($product) as $notification) {
                $notifications[] = $notification;
            }
        });

        return collect($notifications)->sortBy(fn ($item) => [$item['rank'], $item['expired_date'] ?? '9999', mb_strtolower($item['product_name']), $item['product_id']])->values()->all();
    }

    public function checkProduct(Product $product): array
    {
        $stock = $this->stockConditions($product);
        $conditions = collect([...$stock['conditions'], ...$this->expiryConditions($product)])
            ->sortBy(fn ($item) => [$item['rank'], $item['expired_date'] ?? '9999', $item['location_name']])->values();
        if ($conditions->isEmpty()) {
            return [];
        }
        $primary = $conditions->first();
        $locations = $conditions->flatMap(fn ($item) => $item['location_names'])->unique()->values()->all();
        $badges = $conditions->map(fn ($item) => ['label' => $item['badge_label'], 'class' => $item['badge_class']])->unique('label')->values()->all();
        $incoming = 0.0;
        $orders = [];
        if ($stock['conditions'] !== []) {
            foreach ($product->purchaseOrderItems as $item) {
                if (! in_array($item->purchaseOrder?->status, ['pending', 'partial'], true)) {
                    continue;
                }
                // Reserved quantities are represented by replacement POs: do not count twice.
                $quantity = $item->receivableQuantity() * (float) ($item->productUnit?->conversion_to_base ?? ($product->can_retail ? $product->conversion_factor : 1));
                if ($quantity <= 0) {
                    continue;
                }
                $incoming += $quantity;
                $orders[] = ['number' => $item->purchaseOrder->po_number, 'quantity' => $product->displayStock($quantity)];
            }
        }
        $shortfall = max(0, round($stock['shortfall'] - $incoming, 4));
        $advice = '';
        if ($stock['conditions'] !== []) {
            if ($incoming > 0) {
                $advice = 'Sudah dipesan: '.$product->displayStock($incoming).', menunggu penerimaan. ';
                $advice .= $shortfall > 0
                    ? 'Masih kurang '.$product->displayStock($shortfall).' untuk mencapai batas minimum setelah rencana transfer dan PO diterima.'
                    : 'Periksa penerimaan dan distribusi barang sebelum membuat pesanan tambahan.';
            } elseif ($shortfall > 0) {
                $advice = 'Pertimbangkan order ke supplier. Kekurangan untuk mencapai batas minimum: '.$product->displayStock($shortfall).'.';
            } elseif ($stock['transfers'] === []) {
                $advice = 'Stok berada pada batas minimum. Pantau kebutuhan sebelum menambah pesanan.';
            }
        }

        return [array_merge($primary, [
            'product_id' => $product->id, 'product_name' => $product->name, 'barcode' => $product->barcode,
            'type' => $conditions->pluck('type')->unique()->count() > 1 ? 'combined' : $primary['type'],
            'level' => $conditions->contains(fn ($item) => $item['level'] === 'danger') ? 'danger' : 'warning',
            'location_name' => count($locations) > 1 ? count($locations).' lokasi' : ($locations[0] ?? 'Semua lokasi'),
            'location_names' => $locations, 'badges' => $badges, 'conditions' => $conditions->all(),
            'summary' => $primary['message'], 'message' => $conditions->pluck('message')->implode(' '),
            'transfers' => $stock['transfers'], 'incoming_quantity' => $incoming, 'orders' => $orders, 'advice' => $advice, 'shortfall' => $shortfall,
        ])];
    }

    private function stockConditions(Product $product): array
    {
        $ids = $product->productLocations->pluck('location_id')->merge($product->stocks->pluck('location_id'))
            ->merge($product->stockBatches->pluck('location_id'))->unique()->sort()->values();
        $locations = [];
        foreach ($ids as $id) {
            $setting = $product->productLocations->firstWhere('location_id', $id);
            $location = $setting?->location ?? $product->stocks->firstWhere('location_id', $id)?->location ?? $product->stockBatches->firstWhere('location_id', $id)?->location;
            $locations[$id] = ['name' => $location?->name ?? 'Tanpa lokasi', 'quantity' => $this->availableQuantityAt($product, (int) $id),
                'minimum' => (float) ($setting?->min_stock ?? 0), 'monitored' => $setting !== null];
        }
        $total = array_sum(array_column($locations, 'quantity'));
        $minimum = (float) $product->min_stock_total;
        $conditions = [];
        $surplus = [];
        foreach ($locations as $id => $location) {
            $surplus[$id] = max(0, $location['quantity'] - $location['minimum']);
        }
        $transfers = [];
        $locationShortfall = 0.0;
        // Allocate a source's surplus only once, preserving its own minimum.
        foreach ($locations as $id => $location) {
            if (! $location['monitored'] || $location['quantity'] > $location['minimum']) {
                continue;
            }
            $empty = $location['quantity'] <= 0;
            $message = 'Stok '.$product->name.' di '.$location['name'].($empty ? ' habis.' : ' menipis.')
                .' Tersedia '.$product->displayStock($location['quantity']).', minimum '.$product->displayStock($location['minimum']).'.';
            $conditions[] = $this->condition($empty ? 'out_of_stock' : 'low_stock', $message, [$id], [$location['name']]);
            $needed = max(0, $location['minimum'] - $location['quantity']);
            foreach ($surplus as $sourceId => &$available) {
                if ($sourceId === $id || $available <= 0 || $needed <= 0) {
                    continue;
                }
                $quantity = min($available, $needed);
                $transfers[] = ['source_id' => $sourceId, 'destination_id' => $id, 'quantity' => $quantity,
                    'message' => 'Pertimbangkan transfer '.$product->displayStock($quantity).' dari '.$locations[$sourceId]['name'].' ke '.$location['name'].'.'];
                $available -= $quantity;
                $needed -= $quantity;
            }
            unset($available);
            $locationShortfall += $needed;
        }
        if ($total <= 0 || ($minimum > 0 && $total <= $minimum)) {
            $message = 'Total stok layak jual '.$product->name.($total <= 0 ? ' habis.' : ' rendah.')
                .' Tersedia '.$product->displayStock($total).', minimum '.$product->displayStock($minimum).'.';
            $conditions[] = $this->condition($total <= 0 ? 'out_of_stock' : 'low_stock', $message, $ids->all(), array_column($locations, 'name'));
        }

        return ['conditions' => $conditions, 'transfers' => $transfers, 'shortfall' => max($locationShortfall, $minimum - $total, 0)];
    }

    private function expiryConditions(Product $product): array
    {
        if (! $product->is_expirable) {
            return [];
        }

        return $product->stockBatches->filter(fn ($batch) => (float) $batch->quantity_remaining > 0
            && $batch->expired_date && $batch->expired_date->lte(today()->addDays(30)))
            ->map(function ($batch) use ($product) {
                $days = (int) today()->diffInDays($batch->expired_date, false);
                $kind = $days < 0 ? 'expired' : ($days === 0 ? 'expiry_today' : 'expiring');
                $date = $batch->expired_date->format('d/m/Y');
                $text = $days < 0 ? "sudah kedaluwarsa sejak {$date}" : ($days === 0 ? 'kedaluwarsa hari ini' : "akan kedaluwarsa pada {$date} (sisa {$days} hari)");
                $location = $batch->location?->name ?? 'Tanpa lokasi';

                return array_merge($this->condition($kind, "Stok {$product->name} di {$location} {$text}. Sisa ".$product->displayStock((float) $batch->quantity_remaining).'.',
                    [(int) $batch->location_id], [$location]), ['expired_date' => $batch->expired_date->toDateString()]);
            })->values()->all();
    }

    private function condition(string $kind, string $message, array $ids, array $names): array
    {
        $rank = array_search($kind, array_keys(self::FILTERS), true);
        $label = match ($kind) {
            'expired' => 'Kedaluwarsa', 'out_of_stock' => 'Stok Habis', 'expiry_today' => 'Kedaluwarsa Hari Ini',
            'expiring' => 'Hampir Kedaluwarsa', default => 'Stok Rendah',
        };

        return ['kind' => $kind, 'type' => in_array($kind, ['expired', 'expiry_today', 'expiring'], true) ? 'expiry' : 'stock',
            'rank' => $rank, 'level' => $rank <= 2 ? 'danger' : 'warning', 'badge_label' => $label, 'badge_class' => $rank <= 2 ? 'danger' : 'warning text-dark',
            'location_ids' => $ids, 'location_names' => $names, 'location_name' => count($names) === 1 ? $names[0] : 'Semua lokasi', 'message' => $message];
    }

    public function countNotifications(): int
    {
        return count($this->forRequest());
    }

    private function availableQuantityAt(Product $product, int $locationId): float
    {
        if (! $product->is_expirable) {
            return (float) ($product->stocks->firstWhere('location_id', $locationId)?->quantity ?? 0);
        }

        return (float) $product->stockBatches->filter(fn ($batch) => (int) $batch->location_id === $locationId
            && (float) $batch->quantity_remaining > 0 && (! $batch->expired_date || $batch->expired_date->gte(today())))->sum('quantity_remaining');
    }
}
