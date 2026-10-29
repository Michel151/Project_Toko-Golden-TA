<?php

namespace App\Services;

use App\Models\Product;

class NotificationService
{
    public const FILTERS = ['expired' => 'Sudah kedaluwarsa', 'out_of_stock' => 'Stok habis', 'expiry_today' => 'Kedaluwarsa hari ini', 'expiring' => 'Mendekati kedaluwarsa', 'low_stock' => 'Stok rendah'];

    // Share a snapshot within this request only, never across requests.
    public function forRequest(?\Illuminate\Database\Eloquent\Collection $products = null): array
    {
        if (! request()->attributes->has('inventory.notifications')) {
            request()->attributes->set('inventory.notifications', $this->checkAllProducts($products));
        }

        return request()->attributes->get('inventory.notifications');
    }

    public function checkAllProducts(?\Illuminate\Database\Eloquent\Collection $products = null): array
    {
        $notifications = [];
        $relations = [
            'productLocations.location', 'stocks.location', 'stockBatches' => fn ($q) => $q->where('quantity_remaining', '>', 0), 'stockBatches.location', 'buyUnit', 'retailUnit', 'productUnits.unit',
            'purchaseOrderItems' => fn ($q) => $q->whereHas('purchaseOrder', fn ($orders) => $orders->whereIn('status', ['pending', 'partial'])),
            'purchaseOrderItems.productUnit', 'purchaseOrderItems.purchaseOrder',
        ];
        $consume = function ($product) use (&$notifications) {
            foreach ($this->checkProduct($product) as $notification) {
                $notifications[] = $notification;
            }
        };
        if ($products !== null) {
            $products->loadMissing($relations)->each($consume);
        } else {
            Product::active()->with($relations)->each($consume);
        }

        return collect($notifications)->sortBy(fn ($item) => [$item['rank'], $item['expired_date'] ?? '9999', mb_strtolower($item['product_name']), $item['product_id']])->values()->all();
    }

    public function checkProduct(Product $product): array
    {
        $today = today();
        $formatted = [];
        $display = function (float $quantity) use ($product, &$formatted): string {
            $key = (string) $quantity;
            return $formatted[$key] ??= $product->displayStock($quantity);
        };
        $stock = $this->stockConditions($product, $today->toDateString(), $display);
        $conditions = collect([...$stock['conditions'], ...$this->expiryConditions($product, $today, $display)])
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
                $orders[] = ['id' => $item->purchase_order_id, 'number' => $item->purchaseOrder->po_number, 'quantity' => $display($quantity)];
            }
        }
        $shortfall = max(0, round($stock['shortfall'] - $incoming, 4));
        $advice = '';
        if ($stock['conditions'] !== []) {
            if ($incoming > 0) {
                $advice = 'Sudah dipesan: '.$display($incoming).', menunggu penerimaan. ';
                $advice .= $shortfall > 0
                    ? 'Masih kurang '.$display($shortfall).' untuk mencapai batas minimum setelah rencana transfer dan PO diterima.'
                    : 'Periksa penerimaan dan distribusi barang sebelum membuat pesanan tambahan.';
            } elseif ($shortfall > 0) {
                $advice = 'Direkomendasikan pengadaan dari supplier untuk memenuhi kekurangan stok sebesar '.$display($shortfall).'.';
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

    private function stockConditions(Product $product, string $today, callable $display): array
    {
        $ids = $product->productLocations->pluck('location_id')->merge($product->stocks->pluck('location_id'))
            ->merge($product->stockBatches->pluck('location_id'))->unique()->sort()->values();
        $settings = $product->productLocations->keyBy('location_id');
        $stocks = $product->stocks->keyBy('location_id');
        $batches = $product->stockBatches->groupBy('location_id');
        $available = $this->sellableByLocation($product, $today);
        $locations = [];
        foreach ($ids as $id) {
            $setting = $settings->get($id);
            $location = $setting?->location ?? $stocks->get($id)?->location ?? $batches->get($id)?->first()?->location;
            $locations[$id] = ['name' => $location?->name ?? 'Tanpa lokasi', 'quantity' => ($available[$id] ?? 0.0),
                'minimum' => (float) ($setting?->min_stock ?? 0), 'monitored' => $setting !== null,
                'can_supply' => mb_strtolower(trim($location?->name ?? '')) !== 'toko'];
        }
        $total = array_sum(array_column($locations, 'quantity'));
        $minimum = (float) $product->min_stock_total;
        $conditions = [];
        $planned = [];
        $targets = [];
        foreach ($locations as $id => $location) {
            $planned[$id] = $location['quantity'];
            $targets[$id] = ! $location['can_supply'] && $location['quantity'] <= 0
                ? max(1, $location['minimum']) : $location['minimum'];
            if (($location['monitored'] || ! $location['can_supply']) && $location['quantity'] <= $location['minimum']) {
                $empty = $location['quantity'] <= 0;
                $message = 'Stok '.$product->name.' di '.$location['name'].($empty ? ' habis.' : ' menipis.')
                    .' Tersedia '.$display($location['quantity']).', minimum '.$display($location['minimum']).'.';
                $conditions[] = $this->condition($empty ? 'out_of_stock' : 'low_stock', $message, [$id], [$location['name']]);
            }
        }
        $transfers = [];
        // Supply an empty sales location first, including stock below a warehouse's minimum.
        $destinations = array_keys($locations);
        usort($destinations, fn ($a, $b) => [! (! $locations[$a]['can_supply'] && $locations[$a]['quantity'] <= 0), $a]
            <=> [! (! $locations[$b]['can_supply'] && $locations[$b]['quantity'] <= 0), $b]);
        foreach ($destinations as $id) {
            $location = $locations[$id];
            $priority = ! $location['can_supply'] && $location['quantity'] <= 0;
            if (! $location['monitored'] && ! $priority) {
                continue;
            }
            $needed = max(0, $targets[$id] - $planned[$id]);
            foreach ($locations as $sourceId => $source) {
                if ($sourceId === $id || ! $source['can_supply'] || $needed <= 0) {
                    continue;
                }
                $available = max(0, $planned[$sourceId] - ($priority ? 0 : $source['minimum']));
                $quantity = min($available, $needed);
                if ($quantity <= 0) {
                    continue;
                }
                $belowMinimum = $planned[$sourceId] - $quantity < $source['minimum'];
                $transfers[] = ['source_id' => $sourceId, 'destination_id' => $id, 'quantity' => $quantity,
                    'message' => 'Direkomendasikan transfer '.$display($quantity).' dari '.$source['name'].' ke '.$location['name'].'.'
                        .($belowMinimum ? ' Transfer ini diprioritaskan untuk mendukung penjualan di Toko. Stok lokasi asal setelah transfer akan berada di bawah batas minimum.' : '')];
                $planned[$sourceId] -= $quantity;
                $planned[$id] += $quantity;
                $needed -= $quantity;
            }
        }
        $locationShortfall = 0.0;
        foreach ($locations as $id => $location) {
            if ($location['monitored'] || ! $location['can_supply']) {
                $locationShortfall += max(0, $targets[$id] - $planned[$id]);
            }
        }
        if ($total <= 0 || ($minimum > 0 && $total <= $minimum)) {
            $message = 'Total stok layak jual '.$product->name.($total <= 0 ? ' habis.' : ' rendah.')
                .' Tersedia '.$display($total).', minimum '.$display($minimum).'.';
            $conditions[] = $this->condition($total <= 0 ? 'out_of_stock' : 'low_stock', $message, $ids->all(), array_column($locations, 'name'));
        }

        return ['conditions' => $conditions, 'transfers' => $transfers, 'shortfall' => max($locationShortfall, $minimum - $total, 0)];
    }

    private function expiryConditions(Product $product, \Carbon\CarbonInterface $today, callable $display): array
    {
        if (! $product->is_expirable) {
            return [];
        }
        $cutoff = $today->copy()->addDays(30)->toDateString();
        $conditions = [];
        $dates = [];
        foreach ($product->stockBatches as $batch) {
            $quantity = (float) $batch->quantity_remaining;
            $rawDate = ($batch->getAttributes()['expired_date'] ?? null);
            if ($quantity <= 0 || ! $rawDate || substr($rawDate, 0, 10) > $cutoff) {
                continue;
            }
            $dateKey = substr($rawDate, 0, 10);
            if (! isset($dates[$dateKey])) {
                $expiry = \Carbon\Carbon::parse($dateKey);
                $days = (int) $today->diffInDays($expiry, false);
                $kind = $days < 0 ? 'expired' : ($days === 0 ? 'expiry_today' : 'expiring');
                $date = $expiry->format('d/m/Y');
                $text = $days < 0 ? "sudah kedaluwarsa sejak {$date}" : ($days === 0 ? 'kedaluwarsa hari ini' : "akan kedaluwarsa pada {$date} (sisa {$days} hari)");
                $dates[$dateKey] = [$kind, $text];
            }
            [$kind, $text] = $dates[$dateKey];
            $location = $batch->location?->name ?? 'Tanpa lokasi';
            $conditions[] = array_merge($this->condition($kind, "Stok {$product->name} di {$location} {$text}. Sisa ".$display($quantity).'.',
                [(int) $batch->location_id], [$location]), ['expired_date' => $dateKey]);
        }
        return $conditions;
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

    public function sellableByLocation(Product $product, ?string $today = null): array
    {
        if (! $product->is_expirable) {
            return $product->stocks->pluck('quantity', 'location_id')->map(fn ($quantity) => (float) $quantity)->all();
        }
        $today ??= today()->toDateString();
        $quantities = [];
        foreach ($product->stockBatches as $batch) {
            $quantity = (float) $batch->quantity_remaining;
            $date = ($batch->getAttributes()['expired_date'] ?? null);
            if ($quantity > 0 && (! $date || substr($date, 0, 10) >= $today)) {
                $id = $batch->location_id;
                $quantities[$id] = ($quantities[$id] ?? 0) + $quantity;
            }
        }
        return $quantities;
    }
}
