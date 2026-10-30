<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    public function index(Request $request, NotificationService $service)
    {
        $groups = ['expiry_all'=>['expired', 'expiry_today', 'expiring'], 'stock_all'=>['out_of_stock', 'low_stock'], 'return_all'=>['return_pending','return_approval']];
        $conditionOptions = NotificationService::FILTERS + ['expiry_all'=>'Risiko kedaluwarsa (semua)', 'stock_all'=>'Stok habis/rendah', 'return_all'=>'Retur/klaim (semua)'];
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'condition' => ['nullable', Rule::in(array_keys($conditionOptions))],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'product_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $all = collect($service->forRequest());
        $matchesCondition = fn ($condition) => (empty($filters['condition']) || in_array($condition['kind'], $groups[$filters['condition']] ?? [$filters['condition']], true))
            && (empty($filters['location_id']) || in_array((int) $filters['location_id'], $condition['location_ids']));
        $items = $all->filter(function ($item) use ($filters, $matchesCondition) {
            $search = mb_strtolower(trim($filters['search'] ?? ''));
            if ($search !== '' && ! str_contains(mb_strtolower($item['product_name'].' '.$item['barcode']), $search)) {
                return false;
            }
            if (! empty($filters['product_id']) && $item['product_id'] !== (int) $filters['product_id']) {
                return false;
            }

            // Both filters must match the same condition, not unrelated locations on a product.
            return collect($item['conditions'])->contains($matchesCondition);
        })->map(function ($item) use ($matchesCondition) {
            // Preserve all details and actions; highlight the highest-priority matching condition.
            $primary = collect($item['conditions'])->first($matchesCondition);
            $item['summary'] = $primary['message'];
            return $item;
        })->values();
        $countConditions = fn (array $kinds) => $items->filter(fn ($item) => collect($item['conditions'])
            ->contains(fn ($condition) => $matchesCondition($condition) && in_array($condition['kind'], $kinds, true)))->count();
        $summary = [
            'products' => $items->count(),
            'expiry' => $countConditions(['expired', 'expiry_today', 'expiring']),
            'stock' => $countConditions(['out_of_stock', 'low_stock']),
            'returns' => $countConditions(['return_pending','return_approval']),
        ];
        $page = min((int) ($filters['page'] ?? 1), max(1, (int) ceil($items->count() / 12)));
        $notifications = new LengthAwarePaginator($items->forPage($page, 12)->values(), $items->count(), 12, $page,
            ['path' => $request->url(), 'query' => $request->except('page')]);

        return view('notifications.index', [
            'notifications' => $notifications, 'totalProducts' => $all->count(), 'summary' => $summary,
            'hasAppliedFilters' => trim($filters['search'] ?? '') !== '' || ! empty($filters['condition']) || ! empty($filters['location_id']) || ! empty($filters['product_id']),
            'filters' => $filters, 'conditionOptions' => $conditionOptions,
            'locations' => Location::orderBy('name')->get(),
        ]);
    }
}
