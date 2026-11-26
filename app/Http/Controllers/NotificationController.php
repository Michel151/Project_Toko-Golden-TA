<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    // Menampilkan notifikasi sesuai filter dengan maksimal 12 produk per halaman.
    public function index(Request $request, NotificationService $service)
    {
        // Menggabungkan beberapa kondisi menjadi satu pilihan filter.
        $groups = ['expiry_all'=>['expired', 'expiry_today', 'expiry_urgent', 'expiring'], 'stock_all'=>['out_of_stock', 'low_stock'], 'return_all'=>['return_pending','return_approval']];
        $conditionOptions = NotificationService::FILTERS + ['expiry_all'=>'Risiko kedaluwarsa (semua)', 'stock_all'=>'Stok habis/rendah', 'return_all'=>'Retur/klaim (semua)'];
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'condition' => ['nullable', Rule::in(array_keys($conditionOptions))],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'product_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        // Mengambil semua produk yang perlu perhatian sebelum filter diterapkan.
        $all = collect($service->forRequest());
        // Memeriksa kecocokan jenis kondisi dan lokasi yang dipilih.
        $matchesCondition = fn ($condition) => (empty($filters['condition']) || in_array($condition['kind'], $groups[$filters['condition']] ?? [$filters['condition']], true))
            && (empty($filters['location_id']) || in_array((int) $filters['location_id'], $condition['location_ids']));
        // Menyaring produk berdasarkan nama atau barcode, ID produk, dan kondisinya.
        $items = $all->filter(function ($item) use ($filters, $matchesCondition) {
            $search = mb_strtolower(trim($filters['search'] ?? ''));
            if ($search !== '' && ! str_contains(mb_strtolower($item['product_name'].' '.$item['barcode']), $search)) {
                return false;
            }
            if (! empty($filters['product_id']) && $item['product_id'] !== (int) $filters['product_id']) {
                return false;
            }

            // Jenis kondisi dan lokasi harus cocok pada rincian kondisi yang sama.
            return collect($item['conditions'])->contains($matchesCondition);
        })->map(function ($item) use ($matchesCondition) {
            // Memilih ringkasan sesuai filter; seluruh rincian dan tindakan tetap tersedia.
            $primary = collect($item['conditions'])->first($matchesCondition);
            $item['summary'] = $primary['message'];
            return $item;
        })->values();
        // Satu produk dihitung sekali dalam setiap kelompok meskipun memiliki beberapa kondisi.
        $countConditions = fn (array $kinds) => $items->filter(fn ($item) => collect($item['conditions'])
            ->contains(fn ($condition) => $matchesCondition($condition) && in_array($condition['kind'], $kinds, true)))->count();
        $summary = [
            'products' => $items->count(),
            'expiry' => $countConditions(['expired', 'expiry_today', 'expiry_urgent', 'expiring']),
            'stock' => $countConditions(['out_of_stock', 'low_stock']),
            'returns' => $countConditions(['return_pending','return_approval']),
        ];
        // Membatasi nomor halaman agar tidak melewati halaman terakhir.
        $page = min((int) ($filters['page'] ?? 1), max(1, (int) ceil($items->count() / 12)));
        // Mengambil 12 produk untuk halaman ini dan mempertahankan filter pada tautan halaman.
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
