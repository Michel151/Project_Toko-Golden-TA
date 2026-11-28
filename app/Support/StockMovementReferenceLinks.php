<?php

namespace App\Support;

use App\Models\PurchaseReceipt;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\StockOpname;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class StockMovementReferenceLinks
{
    // Memasang link setelah memeriksa keberadaan dokumen dan izin pengguna.
    public static function attach(Collection $rows, User $user): void
    {
        $routes = [
            PurchaseReceipt::class => 'purchase.receipts.show',
            PurchaseReturn::class => 'purchase.returns.show',
            Sale::class => 'sale.sales.show',
            StockTransfer::class => 'inventory.transfers.show',
            StockOpname::class => 'inventory.opnames.show',
        ];

        $groups = $rows->filter(fn ($row) => $row->reference_type && $row->reference_id && $row->reference_number)
            ->groupBy(fn ($row) => Relation::getMorphedModel($row->reference_type) ?? $row->reference_type);

        foreach ($groups as $model => $movements) {
            $route = $routes[$model] ?? null;
            if (! $route || ! $user->canAccessRoute($route)) {
                continue;
            }

            $columns = $model === Sale::class ? ['id', 'created_by'] : ['id'];
            $documents = $model::query()->whereKey($movements->pluck('reference_id')->unique()->all())->get($columns)->keyBy('id');

            foreach ($movements as $movement) {
                $document = $documents->get($movement->reference_id);
                if ($document && (! ($document instanceof Sale) || $document->isAccessibleBy($user))) {
                    $movement->card_reference_url = route($route, $document);
                }
            }
        }
    }
}
