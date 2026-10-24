<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class InventoryReconciliationService
{
    public function build(string $from, string $to, string $invoiceCost, string $legacyCost, int $incompleteCosts): array
    {
        $ledger = $this->ledger($to);
        $firstDates = (clone $ledger)->select('entries.product_id')
            ->selectRaw('MIN(entries.event_date) AS first_date')->groupBy('entries.product_id');
        $opening = $this->snapshot($ledger, $from, true);
        $closing = $this->snapshot($ledger, $to, false);
        $import = "(entries.event_key LIKE 'import-movement:%' OR entries.event_key LIKE 'legacy-import-movement:%')";
        $changes = (clone $ledger)->whereBetween('entries.event_date', [$from, $to])->selectRaw("
            COALESCE(SUM(CASE WHEN entries.kind = 'receipt' AND NOT $import THEN entries.value_change ELSE 0 END),0) AS receipts,
            COALESCE(SUM(CASE WHEN entries.kind = 'receipt' AND $import THEN entries.value_change ELSE 0 END),0) AS imports,
            COALESCE(SUM(CASE WHEN entries.kind = 'opening' THEN entries.value_change ELSE 0 END),0) AS openings,
            COALESCE(SUM(CASE WHEN entries.kind = 'stock_gain' THEN entries.value_change ELSE 0 END),0) AS gains,
            COALESCE(SUM(CASE WHEN entries.kind = 'stock_loss' THEN entries.value_change ELSE 0 END),0) AS losses,
            COALESCE(SUM(CASE WHEN entries.kind = 'sale' THEN -entries.value_change ELSE 0 END),0) AS sale_cost,
            COALESCE(SUM(CASE WHEN entries.kind NOT IN ('receipt','opening','stock_gain','stock_loss','sale') THEN entries.value_change ELSE 0 END),0) AS other_value,
            COALESCE(SUM(CASE WHEN entries.kind NOT IN ('receipt','opening','stock_gain','stock_loss','sale') THEN 1 ELSE 0 END),0) AS other_count
        ")->first();
        $amount = fn ($value) => BigDecimal::of((string) $value)->toScale(2, RoundingMode::HalfUp);
        $receipts = $amount($changes->receipts);
        $imports = $amount($changes->imports);
        $openings = $amount($changes->openings);
        $gains = $amount($changes->gains);
        $losses = $amount($changes->losses);
        $other = $amount($changes->other_value);
        $cost = $amount($invoiceCost);
        $expected = $opening->plus($receipts)->plus($imports)->plus($openings)->plus($gains)->plus($losses)->plus($other)->minus($cost);
        $difference = $expected->minus($closing);
        $costDifference = $amount($changes->sale_cost)->minus($cost);

        $legacyStock = DB::table('stocks')->leftJoinSub(clone $firstDates, 'cost_start', 'cost_start.product_id', '=', 'stocks.product_id')
            ->whereDate('stocks.created_at', '<=', $to)->where('stocks.quantity', '!=', 0)
            ->where(fn ($query) => $query->whereNull('cost_start.first_date')->orWhere('cost_start.first_date', '>', $to))
            ->pluck('stocks.product_id');
        $opnameLedger = (clone $ledger)->selectRaw('1')->whereIn('entries.kind', ['stock_loss', 'stock_gain'])
            ->whereColumn('entries.product_id', 'movement.product_id')->whereColumn('entries.reference_type', 'movement.reference_type')
            ->whereColumn('entries.reference_id', 'movement.reference_id');
        $legacyMovements = DB::table('stock_movements as movement')
            ->leftJoinSub(clone $firstDates, 'cost_start', 'cost_start.product_id', '=', 'movement.product_id')
            ->where('movement.movement_date', '<=', $to)->where('movement.quantity', '!=', 0)
            ->where(function ($query) use ($from, $to, $opnameLedger) {
                $query->whereNull('cost_start.first_date')
                    ->orWhere(fn ($old) => $old->where('cost_start.first_date', '>=', $from)->whereColumn('movement.movement_date', '<', 'cost_start.first_date'))
                    ->orWhere(fn ($old) => $old->whereBetween('movement.movement_date', [$from, $to])->where('movement.movement_type', 'opname_adjustment')
                        ->whereNotNull('movement.reference_id')->whereNotExists($opnameLedger));
            })->pluck('movement.product_id');
        $legacySales = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'paid')->whereBetween('sales.sale_date', [$from, $to])
            ->where('sale_items.cost_method', '!=', 'moving_average')->pluck('sale_items.product_id');
        $legacyProducts = $legacyStock->merge($legacyMovements)->merge($legacySales)->unique()->count();
        $hasLedger = (clone $ledger)->where('entries.event_date', '<=', $to)->exists();
        $available = $hasLedger || $legacyProducts === 0;
        $calculable = $available && $incompleteCosts === 0;
        $notes = ['Cakupan: saldo biaya tercatat seluruh lokasi, termasuk reservasi penjualan. Transfer antar lokasi tidak menambah nilai persediaan.'];
        if ($legacyProducts) $notes[] = $legacyProducts.' barang memiliki stok atau riwayat sebelum moving average. Nilai stok lama yang belum tercatat tidak termasuk dan tidak dianggap nol.';
        if ((float) $legacyCost != 0) $notes[] = 'HPP historis tetap dihitung pada laporan laba rugi, tetapi tidak dikurangkan lagi pada rekonsiliasi moving average.';
        if (!$available) $notes[] = 'Riwayat nilai persediaan untuk periode ini belum tersedia.';
        if ($incompleteCosts) $notes[] = 'HPP invoice moving average belum lengkap; hasil perhitungan akhir belum dapat ditentukan.';
        if ((int) $changes->other_count) $notes[] = 'Terdapat mutasi nilai lainnya yang perlu diperiksa.';
        if ($calculable && (!$difference->isZero() || !$costDifference->isZero())) $notes[] = 'Saldo akhir atau HPP invoice tidak sesuai dengan riwayat biaya. Periksa mutasi sebelum menggunakan hasil rekonsiliasi.';
        $status = !$available ? 'unavailable' : (!$calculable || !$difference->isZero() || !$costDifference->isZero() ? 'mismatch'
            : ($legacyProducts || (int) $changes->other_count ? 'partial' : 'matched'));
        $value = fn (BigDecimal $number) => $available ? (float) (string) $number : null;
        $lines = [
            ['Persediaan awal periode', $value($opening), false],
            ['Penerimaan pembelian', $value($receipts), false],
            ['Stok masuk melalui impor', $value($imports), false],
        ];
        if (!$openings->isZero()) $lines[] = ['Saldo awal penerapan metode', $value($openings), false];
        $lines[] = ['Penyesuaian opname bertambah', $value($gains), false];
        $lines[] = ['Penyesuaian opname berkurang', $value($losses), false];
        if ((int) $changes->other_count) $lines[] = ['Mutasi nilai lainnya', $value($other), false];
        $lines[] = ['HPP penjualan moving average', $calculable ? -(float) (string) $cost : null, false];
        $lines[] = ['Persediaan akhir hasil perhitungan', $calculable ? (float) (string) $expected : null, true, 'total'];
        $lines[] = ['Persediaan akhir tercatat', $value($closing), true, 'total'];
        $lines[] = ['Selisih rekonsiliasi', $calculable ? (float) (string) $difference : null, true, 'total'];
        return [
            'lines' => $lines, 'notes' => $notes, 'status' => $status,
            'statusLabel' => ['matched' => 'Seimbang', 'partial' => 'Sebagian Data', 'mismatch' => 'Perlu Diperiksa', 'unavailable' => 'Belum Tersedia'][$status],
            'available' => $available, 'legacyProducts' => $legacyProducts,
            'opening' => $value($opening), 'closing' => $value($closing),
            'calculatedClosing' => $calculable ? (float) (string) $expected : null,
            'difference' => $calculable ? (float) (string) $difference : null,
        ];
    }

    private function ledger(string $to): Builder
    {
        return DB::table('inventory_cost_entries as entries')
            ->join('inventory_cost_balances as balances', 'balances.product_id', '=', 'entries.product_id')
            ->whereNotNull('balances.activated_at')
            ->where(fn ($query) => $query->where('entries.kind', '!=', 'opening')->orWhereDate('balances.activated_at', '<=', $to))
            // An automatic zero start supersedes unactivated opening drafts, not their audit history.
            ->whereNotExists(fn ($reset) => $reset->selectRaw('1')->from('inventory_cost_entries as reset')
                ->whereColumn('reset.product_id', 'entries.product_id')->where('reset.kind', 'automatic_start')->whereColumn('reset.id', '>=', 'entries.id'));
    }

    private function snapshot(Builder $ledger, string $date, bool $before): BigDecimal
    {
        $latest = (clone $ledger)->where('entries.event_date', $before ? '<' : '<=', $date)
            ->selectRaw('MAX(entries.id) AS id')->groupBy('entries.product_id');
        $value = DB::table('inventory_cost_entries')->whereIn('id', $latest)->sum('value_after');
        return BigDecimal::of((string) $value)->toScale(2, RoundingMode::HalfUp);
    }
}
