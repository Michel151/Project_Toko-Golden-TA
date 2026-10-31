<?php

namespace App\Services;

use App\Models\{InventoryCostBalance, Product, Sale, Stock, StockMovement};
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MovingAverageCostService
{
    public function enabled(): bool
    {
        $settings = DB::table('inventory_cost_settings')->where('id', 1)->firstOrFail();
        return $settings->enabled_at !== null || (bool) $settings->automatic_from_zero;
    }

    public function automatic(): bool
    {
        return (bool) DB::table('inventory_cost_settings')->where('id', 1)->value('automatic_from_zero');
    }

    public function canTrack(Product $product): bool
    {
        if (!$this->enabled()) return false;
        if ($product->costBalance?->activated_at) return true;
        if (!$this->automatic()) return false;
        try { return BigDecimal::of($this->ownedQuantity($product->id))->isZero(); }
        catch (ValidationException) { return false; }
    }

    /** Read-only eligibility for a page of products, without per-product queries. */
    public function canTrackMany(\Illuminate\Database\Eloquent\Collection $products): array
    {
        $result = $products->mapWithKeys(fn (Product $product) => [$product->id => false])->all();
        if ($products->isEmpty()) return $result;
        $settings = DB::table('inventory_cost_settings')->where('id', 1)->firstOrFail();
        if ($settings->enabled_at === null && !(bool) $settings->automatic_from_zero) return $result;

        $products->loadMissing('costBalance');
        $pending = [];
        foreach ($products as $product) {
            if ($product->costBalance?->activated_at) $result[$product->id] = true;
            elseif ((bool) $settings->automatic_from_zero) $pending[] = $product->id;
        }
        if (!$pending) return $result;

        $stocks = Stock::whereIn('product_id', $pending)->select('product_id')
            ->selectRaw('SUM(quantity) AS total')->groupBy('product_id')->pluck('total', 'product_id');
        $reserved = DB::table('sale_item_sources')->join('sale_items', 'sale_items.id', '=', 'sale_item_sources.sale_item_id')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')->whereIn('sale_items.product_id', $pending)
            ->where('sales.status', 'pending')->select('sale_items.product_id')
            ->selectRaw('SUM(base_quantity) AS total, SUM(CASE WHEN base_quantity IS NULL THEN 1 ELSE 0 END) AS unknown_quantity')
            ->groupBy('sale_items.product_id')->get()->keyBy('product_id');
        foreach ($pending as $id) {
            $held = $reserved->get($id);
            // Match ownedQuantity(): unknown legacy reservations cannot start automatic costing.
            if ($held && $held->unknown_quantity > 0) continue;
            $result[$id] = BigDecimal::of((string) ($stocks->get($id) ?? '0'))
                ->plus((string) ($held?->total ?? '0'))->toScale(4, RoundingMode::HalfUp)->isZero();
        }
        return $result;
    }

    // All stock/cost writers take this gate before changing quantities or valuation.
    public function lock(): void
    {
        DB::table('inventory_cost_settings')->where('id', 1)->lockForUpdate()->firstOrFail();
    }

    public static function money(mixed $value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(2, RoundingMode::HalfUp);
    }

    public function ownedQuantity(int $productId, ?int $locationId = null): string
    {
        $stock = Stock::where('product_id', $productId);
        $reserved = DB::table('sale_item_sources')->join('sale_items', 'sale_items.id', '=', 'sale_item_sources.sale_item_id')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')->where('sale_items.product_id', $productId)->where('sales.status', 'pending');
        if ($locationId !== null) {
            $stock->where('location_id', $locationId);
            $reserved->where('sale_item_sources.location_id', $locationId);
        }
        if ((clone $reserved)->whereNull('base_quantity')->exists()) $this->invalid('Nota menunggu pembayaran lama belum memiliki konversi stok yang pasti. Periksa atau selesaikan nota tersebut sebelum memulai HPP otomatis.');
        return (string) BigDecimal::of((string) $stock->sum('quantity'))->plus((string) $reserved->sum('base_quantity'))->toScale(4, RoundingMode::HalfUp);
    }

    public function prepare(Product $product): ?InventoryCostBalance
    {
        if (!$this->enabled()) return null;
        $state = InventoryCostBalance::whereKey($product->id)->lockForUpdate()->first();
        if ($state?->activated_at) return $state;
        if ($this->automatic()) {
            // Never value unknown legacy stock at zero or ignore held stock at cutover.
            try { $quantity = $this->ownedQuantity($product->id); }
            catch (ValidationException) { return null; }
            if (!BigDecimal::of($quantity)->isZero()) return null;
            $state ??= new InventoryCostBalance(['product_id' => $product->id]);
            $oldQuantity = $state->quantity ?? '0';
            $oldValue = $state->inventory_value ?? '0';
            $state->fill(['quantity' => 0, 'inventory_value' => 0, 'average_cost' => 0, 'activated_at' => now(), 'last_event_date' => null])->save();
            if ((float) $oldQuantity !== 0.0 || (float) $oldValue !== 0.0) {
                $this->entry($state, 'automatic_start', 'automatic-start:'.$product->id, (string) BigDecimal::of($oldQuantity)->negated(),
                    (string) BigDecimal::of($oldValue)->negated(), today()->toDateString(), $product, 'Mulai otomatis dari stok nol; verifikasi lama yang belum aktif tetap tersimpan dalam riwayat.');
            }
            return $state;
        }
        if (!$state) {
            if ((float) $this->ownedQuantity($product->id) > .00001) $this->invalid('Saldo awal biaya '.$product->name.' belum diverifikasi owner.');
            $state = InventoryCostBalance::create(['product_id' => $product->id, 'quantity' => 0, 'inventory_value' => 0, 'average_cost' => 0, 'activated_at' => now(), 'last_event_date' => today()->toDateString()]);
        }
        return $state;
    }

    public function opening(Product $product, string $value, string $expectedQuantity, string $notes): void
    {
        DB::transaction(function () use ($product, $value, $expectedQuantity, $notes) {
            $this->lock();
            if ($this->enabled()) $this->invalid('Moving average sudah aktif. Saldo awal tidak dapat ditimpa.');
            $quantity = $this->ownedQuantity($product->id);
            if (BigDecimal::of($quantity)->compareTo($expectedQuantity) !== 0) $this->invalid('Stok berubah. Muat ulang halaman dan verifikasi ulang saldo awal.');
            if (((float) $quantity > 0 && (float) $value <= 0) || ((float) $quantity === 0.0 && (float) $value !== 0.0)) $this->invalid('Nilai saldo awal harus positif untuk stok yang tersedia, dan nol jika stok kosong.');
            $value = self::money($value);
            $state = InventoryCostBalance::firstOrNew(['product_id' => $product->id]);
            $quantityChange = (string) BigDecimal::of($quantity)->minus($state->quantity ?? '0');
            $valueChange = (string) BigDecimal::of($value)->minus($state->inventory_value ?? '0');
            $state->fill(['quantity' => $quantity, 'inventory_value' => $value, 'average_cost' => $this->average($value, $quantity),
                'last_event_date' => today()->toDateString(), 'baseline_movement_id' => StockMovement::where('product_id', $product->id)->max('id') ?? 0])->save();
            $this->entry($state, 'opening', 'opening:'.str()->uuid(), $quantityChange, $valueChange, today()->toDateString(), $product, $notes);
            AuditService::record('opening', 'inventory_cost', 'Memverifikasi saldo awal biaya '.$product->name, $state, null, $state->toArray());
        }, 3);
    }

    public function activate(): void
    {
        DB::transaction(function () {
            $this->lock();
            if ($this->enabled()) $this->invalid('Moving average sudah aktif.');
            foreach (Product::orderBy('id')->cursor() as $product) {
                $quantity = $this->ownedQuantity($product->id);
                $state = InventoryCostBalance::whereKey($product->id)->lockForUpdate()->first();
                if ((float) $quantity === 0.0 && !$state) continue;
                if (!$state || BigDecimal::of($quantity)->compareTo($state->quantity) !== 0
                    || (int) $state->baseline_movement_id !== (int) (StockMovement::where('product_id', $product->id)->max('id') ?? 0)) {
                    $this->invalid('Saldo awal '.$product->name.' belum diverifikasi atau stok telah berubah.');
                }
            }
            InventoryCostBalance::query()->update(['activated_at' => now(), 'last_event_date' => today()->toDateString()]);
            DB::table('inventory_cost_settings')->where('id', 1)->update(['enabled_at' => now(), 'enabled_by' => auth()->id()]);
            AuditService::record('activate', 'inventory_cost', 'Mengaktifkan HPP moving average; nota lama dipertahankan.');
        }, 3);
    }

    private function average(string $value, string $quantity): string
    {
        return (float) $quantity > 0 ? (string) BigDecimal::of($value)->dividedBy($quantity, 10, RoundingMode::HalfUp) : '0.0000000000';
    }

    public function receive(Product $product, float $quantity, string $value, ?Model $reference, mixed $date, string $key): void
    {
        if (!$this->enabled()) return;
        $state = $this->prepare($product);
        if (!$state) return;
        if ((float) $value < 0 || ($quantity > 0 && (float) $value <= 0)) $this->invalid('Nilai perolehan '.$product->name.' belum tersedia atau nol. Periksa harga dokumen sumber.');
        $this->post($state, $quantity, self::money($value), 'receipt', $key, $date, $reference);
    }

    public function finalizeSale(Sale $sale): void
    {
        if (!$this->enabled()) return;
        $this->lock();
        $sale->load(['items.sources', 'items.product']);
        $items = $this->automatic() ? $sale->items->whereIn('cost_method', ['reserved', 'moving_average']) : $sale->items;
        $costContext = clone $sale;
        $costContext->setRelation('items', $items);
        app(SaleStockSnapshotService::class)->recoverLegacySources($costContext);
        foreach ($items->sortBy('product_id') as $item) {
            if ($item->cost_method === 'moving_average') $this->invalid('HPP nota ini sudah diproses.');
            $quantity = round((float) $item->sources->sum('base_quantity'), 4);
            if ($quantity <= 0) $this->invalid('Sumber stok nota belum lengkap.');
            $state = $this->prepare($item->product);
            if (!$state) $this->invalid('Saldo biaya nota belum tersedia. Stok lama tidak boleh dinilai nol.');
            if ((float) $state->quantity + .00001 < $quantity) $this->invalid('Saldo biaya tidak cukup untuk '.$item->product->name.'.');
            $value = BigDecimal::of($state->inventory_value)->multipliedBy((string) $quantity)->dividedBy($state->quantity, 2, RoundingMode::HalfUp);
            $average = $state->average_cost;
            $this->post($state, -$quantity, (string) $value->negated(), 'sale', 'sale-item:'.$item->id, $sale->sale_date, $sale);
            $item->update(['cost_method' => 'moving_average', 'cost_base_quantity' => $quantity, 'cost_per_base_unit' => $average,
                'cost_subtotal' => (string) $value, 'cost_price' => (string) $value->dividedBy($item->quantity, 2, RoundingMode::HalfUp)]);
        }
    }

    public function adjustment(Product $product, float $difference, ?string $unitCost, ?Model $reference, mixed $date, string $key): void
    {
        if (!$this->enabled() || abs($difference) <= .00001) return;
        $state = $this->prepare($product);
        if (!$state) return;
        if ($difference > 0) {
            if (!auth()->user()?->isOwner() || $unitCost === null || (float) $unitCost <= 0) $this->invalid('Penambahan stok opname memerlukan nilai perolehan per satuan dasar dan persetujuan owner.');
            $value = self::money(BigDecimal::of($unitCost)->multipliedBy((string) $difference));
        } else {
            if ((float) $state->quantity + .00001 < -$difference) $this->invalid('Pengurangan opname melebihi saldo biaya.');
            $value = (string) BigDecimal::of($state->inventory_value)->multipliedBy((string) $difference)->dividedBy($state->quantity, 2, RoundingMode::HalfUp);
        }
        $this->post($state, $difference, $value, $difference < 0 ? 'stock_loss' : 'stock_gain', $key, $date, $reference);
    }

    private function post(InventoryCostBalance $state, float $quantity, string $value, string $kind, string $key, mixed $date, ?Model $reference): void
    {
        $date = \Carbon\Carbon::parse($date ?? today())->toDateString();
        if ($date > today()->toDateString()) $this->invalid('Tanggal mutasi biaya tidak boleh melewati hari ini.');
        if ($state->last_event_date && $date < $state->last_event_date->toDateString()) $this->invalid('Tanggal transaksi mendahului mutasi biaya terakhir. Gunakan tanggal pencatatan yang berurutan atau lakukan koreksi melalui owner.');
        if (DB::table('inventory_cost_entries')->where('event_key', $key)->exists()) $this->invalid('Mutasi biaya sudah diproses; stok tidak ditambahkan ulang.');
        $newQuantity = BigDecimal::of($state->quantity)->plus((string) $quantity)->toScale(4, RoundingMode::HalfUp);
        $newValue = BigDecimal::of($state->inventory_value)->plus($value)->toScale(2, RoundingMode::HalfUp);
        if ($newQuantity->isNegative() || $newValue->isNegative()) $this->invalid('Saldo jumlah/nilai persediaan tidak boleh negatif.');
        if ($newQuantity->isZero() && !$newValue->isZero()) $this->invalid('Masih ada sisa nilai saat stok menjadi nol. Periksa pembulatan mutasi biaya.');
        $state->update(['quantity' => (string) $newQuantity, 'inventory_value' => (string) $newValue,
            'average_cost' => $this->average((string) $newValue, (string) $newQuantity), 'last_event_date' => $date]);
        $this->entry($state, $kind, $key, (string) $quantity, $value, $date, $reference);
    }

    private function entry(InventoryCostBalance $state, string $kind, string $key, string $quantity, string $value, string $date, ?Model $reference, ?string $notes = null): void
    {
        DB::table('inventory_cost_entries')->insert(['product_id' => $state->product_id, 'event_key' => $key, 'kind' => $kind, 'event_date' => $date,
            'quantity_change' => $quantity, 'value_change' => $value, 'quantity_after' => $state->quantity, 'value_after' => $state->inventory_value,
            'average_after' => $state->average_cost, 'reference_type' => $reference?->getMorphClass(), 'reference_id' => $reference?->getKey(),
            'notes' => $notes, 'created_by' => auth()->id(), 'created_at' => now()]);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['costing' => $message]);
    }
}
