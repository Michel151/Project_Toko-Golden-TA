<?php

namespace App\Services;

use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HeldSaleService
{
    public function __construct(private StockService $stockService) {}

    public function cancel(Sale $sale, bool $automatic = false): bool
    {
        return DB::transaction(function () use ($sale, $automatic) {
            $locked = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                return false;
            }

            $this->stockService->restoreHeldSale($locked);
            $locked->update(['status' => $automatic ? 'cancelled_auto' : 'cancelled']);

            return true;
        });
    }

    public function cancelOverdue(): int
    {
        $count = 0;
        Sale::where('status', 'pending')
            ->where('hold_until', '<=', now('Asia/Jakarta')->format('Y-m-d H:i:s'))
            ->orderBy('id')
            ->pluck('id')
            ->each(function ($id) use (&$count) {
                try {
                    if ($this->cancel(Sale::findOrFail($id), true)) {
                        $count++;
                    }
                } catch (ValidationException $exception) {
                    report($exception);
                }
            });

        return $count;
    }
}
