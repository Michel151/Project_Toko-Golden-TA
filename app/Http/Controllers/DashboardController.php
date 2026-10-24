<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDraft;
use App\Models\PurchaseOrder;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\StockBatch;
use App\Models\StockOpname;
use App\Models\StockTransfer;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(protected NotificationService $notificationService) {}

    public function index()
    {
        if (auth()->user()->isKasir()) {
            return auth()->user()->hasPermission('sale.sales.view')
                ? $this->cashierDashboard() : view('dashboard.access');
        }
        if (auth()->user()->isStafGudang()) {
            $hasWarehouseAccess = collect(['inventory.stock.view', 'inventory.transfers.view', 'inventory.opnames.view'])
                ->every(fn ($permission) => auth()->user()->hasPermission($permission));
            return $hasWarehouseAccess ? $this->warehouseDashboard() : view('dashboard.access');
        }

        abort_unless(auth()->user()->isOwner(), 403);

        $today = now('Asia/Jakarta')->startOfDay();

        $salesToday = Sale::paid()->whereDate('sale_date', $today)->sum('total_amount');
        $salesCount = Sale::paid()->whereDate('sale_date', $today)->count();
        $salesYesterday = Sale::paid()->whereDate('sale_date', $today->copy()->subDay())->sum('total_amount');
        $dailyGrowth = $salesYesterday > 0
            ? (($salesToday - $salesYesterday) / $salesYesterday) * 100
            : null;

        $monthSalesQuery = Sale::paid()->whereDate('sale_date', '>=', $today->copy()->startOfMonth()->toDateString())
            ->whereDate('sale_date', '<=', $today->toDateString());
        $salesMonth = (clone $monthSalesQuery)->sum('total_amount');
        $salesMonthCount = (clone $monthSalesQuery)->count();
        $averageTransaction = $salesMonthCount > 0 ? $salesMonth / $salesMonthCount : 0;

        $salesPreviousMonth = Sale::paid()->whereDate('sale_date', '>=', $today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString())
            ->whereDate('sale_date', '<=', $today->copy()->subMonthNoOverflow()->toDateString())->sum('total_amount');
        $monthlyGrowth = $salesPreviousMonth > 0
            ? (($salesMonth - $salesPreviousMonth) / $salesPreviousMonth) * 100
            : null;

        $monthItems = \App\Models\SaleItem::whereHas('sale', fn ($query) => $query->paid()
            ->whereDate('sale_date', '>=', $today->copy()->startOfMonth()->toDateString())->whereDate('sale_date', '<=', $today->toDateString()));
        $monthCost = (float) (clone $monthItems)->sum('cost_subtotal');
        $missingCostCount = (clone $monthItems)->where(fn ($q) => $q->whereNull('cost_subtotal')->orWhere('cost_subtotal', '<', 0)
            ->orWhere(fn ($q) => $q->where('cost_subtotal', 0)->where('cost_method', '!=', 'moving_average')))->count();
        $salesWithoutItemsCount = (clone $monthSalesQuery)->whereDoesntHave('items')->count();
        $grossProfit = $salesMonth - $monthCost;
        $pendingSalesQuery = Sale::where('status', 'pending');
        $pendingSalesCount = (clone $pendingSalesQuery)->count();
        $pendingSalesAmount = (float) (clone $pendingSalesQuery)->sum('total_amount');

        // PO pending
        $pendingPO = PurchaseOrder::whereIn('status', ['pending', 'partial'])
            ->whereHas('items', fn ($query) => $query->whereRaw('quantity_received + quantity_reserved < quantity_ordered'))->count();

        $totalProducts = Product::active()->count();
        $expiringSoonCount = StockBatch::where('quantity_remaining', '>', 0)
            ->whereHas('product', fn ($query) => $query->active()->where('is_expirable', true))
            ->whereDate('expired_date', '>=', $today)
            ->whereDate('expired_date', '<=', $today->copy()->addDays(30))
            ->count();
        $expiredBatchCount = StockBatch::where('quantity_remaining', '>', 0)
            ->whereHas('product', fn ($query) => $query->active()->where('is_expirable', true))
            ->whereDate('expired_date', '<', $today)
            ->count();
        $expiringBatches = StockBatch::with(['product', 'location'])
            ->whereHas('product', fn ($query) => $query->active()->where('is_expirable', true))
            ->where('quantity_remaining', '>', 0)
            ->whereNotNull('expired_date')
            ->whereDate('expired_date', '<=', $today->copy()->addDays(30))
            ->orderBy('expired_date')
            ->limit(5)
            ->get();

        $salesChartRaw = Sale::select(
                DB::raw('DATE(sale_date) as date'),
                DB::raw('SUM(total_amount) as total')
            )
            ->paid()
            ->whereDate('sale_date', '>=', $today->copy()->subDays(29)->toDateString())
            ->whereDate('sale_date', '<=', $today->toDateString())
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('total', 'date');

        $salesChart = collect(range(29, 0))->map(function ($daysAgo) use ($today, $salesChartRaw) {
            $date = $today->copy()->subDays($daysAgo);

            return [
                'label' => $date->locale('id')->translatedFormat('d M'),
                'total' => (float) ($salesChartRaw[$date->toDateString()] ?? 0),
            ];
        });

        $products = Product::active()->with(['costBalance', 'stocks', 'stockBatches', 'buyUnit', 'retailUnit', 'category', 'productUnits.unit'])->get();
        $sellableStock = $products->mapWithKeys(fn ($product) => [$product->id => $product->is_expirable
            ? (float) $product->stockBatches->filter(fn ($batch) => (float) $batch->quantity_remaining > 0 && (! $batch->expired_date || $batch->expired_date->toDateString() >= $today->toDateString()))->sum('quantity_remaining')
            : (float) $product->stocks->sum('quantity')]);
        $lowStockProducts = $products
            ->filter(fn($product) => $sellableStock[$product->id] <= (float) $product->min_stock_total)
            ->take(10);
        $outOfStockCount = $products
            ->filter(fn($product) => $sellableStock[$product->id] <= 0)
            ->count();
        $inventoryValue = $products->sum(function ($product) {
            return $product->inventoryValue();
        });

        $topProductQuery = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', 'paid')
            ->whereDate('sales.sale_date', '>=', $today->copy()->subDays(29))
            ->whereDate('sales.sale_date', '<=', $today)
            ->groupBy('products.id', 'products.name');
        $topProducts = (clone $topProductQuery)->orderByDesc(DB::raw('SUM(sale_items.subtotal)'))->limit(5)->get([
                'products.name',
                DB::raw('SUM(sale_items.subtotal) as revenue'),
                DB::raw('SUM(sale_items.quantity) as quantity'),
            ]);
        $topQuantityProducts = (clone $topProductQuery)
            ->join('product_units as sale_unit', 'sale_unit.id', '=', 'sale_items.product_unit_id')
            ->orderByDesc(DB::raw('SUM(sale_items.quantity * sale_unit.conversion_to_base)'))->limit(5)
            ->get(['products.id','products.name',DB::raw('SUM(sale_items.quantity * sale_unit.conversion_to_base) as base_quantity')]);
        $rankedProducts = Product::with('productUnits.unit')->whereIn('id', $topQuantityProducts->pluck('id'))->get()->keyBy('id');
        $topQuantityProducts->each(function ($item) use ($rankedProducts) {
            $item->base_unit = $rankedProducts[$item->id]?->productUnits->firstWhere('is_base_unit', true)?->unit?->name ?? 'satuan dasar';
        });

        $paymentMethods = (clone $monthSalesQuery)
            ->select('payment_method', DB::raw('SUM(total_amount) as total'))
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        $locationValues = Location::orderBy('name')->get()->map(function ($location) use ($products) {
            $value = $products->sum(function ($product) use ($location) {
                return $product->inventoryValue((int) $location->id);
            });
            return (object) ['id' => $location->id, 'name' => $location->name, 'value' => $value];
        })->sortByDesc('value')->values();

        $recentSales = Sale::with('createdBy')
            ->whereIn('status', ['paid', 'pending', 'cancelled', 'cancelled_auto'])
            ->latest('sale_date')
            ->latest('id')
            ->limit(8)
            ->get();

        return view('dashboard.index', compact(
            'salesToday', 'salesCount', 'salesYesterday', 'dailyGrowth',
            'salesMonth', 'salesMonthCount', 'averageTransaction', 'monthlyGrowth',
            'pendingPO', 'totalProducts',
            'expiringSoonCount', 'expiredBatchCount', 'expiringBatches',
            'salesChart', 'lowStockProducts', 'outOfStockCount', 'inventoryValue', 'sellableStock',
            'grossProfit', 'missingCostCount', 'salesWithoutItemsCount', 'pendingSalesCount', 'pendingSalesAmount',
            'topProducts', 'topQuantityProducts', 'paymentMethods', 'locationValues', 'recentSales'
        ));
    }

    private function cashierDashboard()
    {
        $userId = auth()->id();
        $now = now('Asia/Jakarta');
        $today = $now->toDateString();
        $todayQuery = Sale::paid()->where('created_by', $userId)->whereDate('sale_date', $today);

        $cashierSalesToday = (float) (clone $todayQuery)->sum('total_amount');
        $cashierTransactionsToday = (clone $todayQuery)->count();
        $cashierCashToday = (float) (clone $todayQuery)->where('payment_method', 'tunai')->sum('total_amount');
        $cashierTransferToday = (float) (clone $todayQuery)->where('payment_method', 'transfer')->sum('total_amount');
        $pendingQuery = Sale::where('created_by', $userId)->where('status', 'pending')->where('hold_until', '>', $now);
        $cashierPendingCount = (clone $pendingQuery)->count();
        $cashierPendingAmount = (float) (clone $pendingQuery)->sum('total_amount');
        $cashierDueSoonCount = (clone $pendingQuery)->where('hold_until', '<=', $now->copy()->addHour())->count();
        $cashierPendingSales = (clone $pendingQuery)->orderBy('hold_until')->orderBy('id')->limit(6)->get();
        $cashierExpiredPendingCount = Sale::where('created_by', $userId)->where('status', 'pending')->where('hold_until', '<=', $now)->count();
        $cashierCancelledToday = Sale::where('created_by', $userId)->whereDate('sale_date', $today)->whereIn('status', ['cancelled', 'cancelled_auto'])->count();
        $statusCounts = Sale::where('created_by', $userId)->whereDate('sale_date', $today)
            ->select('status', DB::raw('COUNT(*) as count'))->groupBy('status')->pluck('count', 'status');
        $cashierStatusCounts = [
            'paid' => (int) ($statusCounts['paid'] ?? 0),
            'pending' => (int) ($statusCounts['pending'] ?? 0),
            'cancelled' => (int) ($statusCounts['cancelled'] ?? 0) + (int) ($statusCounts['cancelled_auto'] ?? 0),
        ];
        $drafts = SaleDraft::where('user_id', $userId)->whereNull('sale_id')->where('status', 'active')->whereNotNull('payload')->latest('updated_at')->get(['id','payload','updated_at']);
        $drafts = $drafts->filter(fn ($draft) => count($draft->payload['items'] ?? []) > 0 || trim($draft->payload['customer_name'] ?? '') !== '' || (float) ($draft->payload['amount_paid'] ?? 0) > 0);
        $cashierDraftCount = $drafts->count();
        $cashierDrafts = $drafts->take(5);

        $cashierRecentSales = Sale::paid()->where('created_by', $userId)
            ->latest('sale_date')->latest('id')->limit(8)->get();

        return view('dashboard.cashier', compact(
            'cashierSalesToday', 'cashierTransactionsToday', 'cashierCashToday', 'cashierTransferToday', 'cashierRecentSales',
            'cashierPendingCount', 'cashierPendingAmount', 'cashierDueSoonCount', 'cashierPendingSales',
            'cashierExpiredPendingCount', 'cashierCancelledToday', 'cashierDraftCount', 'cashierDrafts', 'cashierStatusCounts'
        ));
    }

    private function warehouseDashboard()
    {
        $today = now('Asia/Jakarta')->startOfDay();
        $todayDate = $today->toDateString();
        $expiryCutoff = $today->copy()->addDays(30)->toDateString();
        $products = Product::active()->with(['stocks', 'stockBatches.location', 'buyUnit', 'retailUnit', 'productUnits.unit'])->orderBy('name')->get();
        $availability = $products->mapWithKeys(function ($product) use ($todayDate) {
            // Match the sale screen: expired batches cannot contribute to sellable stock.
            $byLocation = $product->is_expirable
                ? $product->stockBatches->filter(fn ($batch) => (float) $batch->quantity_remaining > 0 && (! $batch->expired_date || $batch->expired_date->toDateString() >= $todayDate))
                    ->groupBy('location_id')->map(fn ($batches) => (float) $batches->sum('quantity_remaining'))
                : $product->stocks->pluck('quantity', 'location_id')->map(fn ($quantity) => (float) $quantity);
            return [$product->id => ['total' => (float) $byLocation->sum(), 'locations' => $byLocation]];
        });
        $outOfStockProducts = $products->filter(fn ($product) => $availability[$product->id]['total'] <= 0);
        $lowStockProducts = $products->filter(fn ($product) => $availability[$product->id]['total'] > 0 && $availability[$product->id]['total'] <= (float) $product->min_stock_total);
        $safeStockCount = $products->count() - $outOfStockProducts->count() - $lowStockProducts->count();

        $priorities = collect();
        $expiredBatchCount = 0;
        $expiringBatchCount = 0;
        foreach ($products as $product) {
            if ($product->is_expirable) {
                foreach ($product->stockBatches as $batch) {
                    if ((float) $batch->quantity_remaining <= 0 || ! $batch->expired_date || $batch->expired_date->toDateString() > $expiryCutoff) continue;
                    $expired = $batch->expired_date->toDateString() < $todayDate;
                    $expired ? $expiredBatchCount++ : $expiringBatchCount++;
                    $priorities->push([
                        'rank' => $expired ? 0 : 1, 'product_name' => $product->name,
                        'location' => $batch->location?->name ?? '-', 'date' => $batch->expired_date->toDateString(),
                        'label' => $expired ? 'Kedaluwarsa' : 'Mendekati Kedaluwarsa', 'color' => $expired ? 'danger' : 'warning text-dark',
                        'detail' => $product->displayStock((float) $batch->quantity_remaining).' · '.$batch->expired_date->format('d/m/Y'),
                        'url' => route('inventory.stock.index', ['search'=>$product->name,'location_id'=>$batch->location_id,'expiry_status'=>$expired ? 'expired' : 'expiring']),
                    ]);
                }
            }
        }
        foreach ($outOfStockProducts->merge($lowStockProducts) as $product) {
            $empty = $availability[$product->id]['total'] <= 0;
            $priorities->push([
                'rank' => $empty ? 2 : 3, 'product_name' => $product->name, 'location' => 'Semua lokasi', 'date' => null,
                'label' => $empty ? 'Stok Layak Jual Habis' : 'Stok Rendah', 'color' => $empty ? 'danger' : 'warning text-dark',
                'detail' => 'Layak jual: '.$product->displayStock($availability[$product->id]['total']).' · Fisik: '.$product->displayStock((float) $product->stocks->sum('quantity')),
                'url' => route('inventory.stock.index', ['search'=>$product->name]),
            ]);
        }
        $priorities = $priorities->sort(fn ($a, $b) => [$a['rank'],$a['date'] ?? '9999',$a['product_name']] <=> [$b['rank'],$b['date'] ?? '9999',$b['product_name']])->values();

        $draftQuery = StockOpname::where('status', 'draft');
        $draftOpnames = (clone $draftQuery)->count();
        $opnameQueue = (clone $draftQuery)->with('location')->orderBy('opname_date')->orderBy('id')->limit(4)->get();
        $pendingReceipts = collect();
        $pendingReceiptCount = 0;
        if (auth()->user()->hasPermission('purchase.receipts.view')) {
            $orderQuery = PurchaseOrder::whereIn('status', ['pending','partial'])->whereHas('items', fn ($q) => $q->whereRaw('quantity_received + quantity_reserved < quantity_ordered'));
            $pendingReceiptCount = (clone $orderQuery)->count();
            $pendingReceipts = (clone $orderQuery)->with('supplier')->withCount(['items as remaining_items_count' => fn ($q) => $q->whereRaw('quantity_received + quantity_reserved < quantity_ordered')])->orderBy('order_date')->orderBy('id')->limit(4)->get();
        }
        $stockedLocations = Location::orderBy('name')->get()->map(function ($location) use ($products, $availability) {
            return ['id'=>$location->id,'name'=>$location->name,
                'sellable'=>$products->filter(fn ($product) => ($availability[$product->id]['locations'][$location->id] ?? 0) > 0)->count(),
                'physical'=>$products->filter(fn ($product) => (float) ($product->stocks->firstWhere('location_id',$location->id)?->quantity ?? 0) > 0)->count()];
        })->sortByDesc('sellable')->values();
        $recentMovements = StockMovement::with(['product.buyUnit','product.retailUnit','product.productUnits.unit','location','createdBy'])->latest('movement_date')->latest('id')->limit(8)->get();

        return view('dashboard.warehouse', [
            'warehouseProductCount'=>$products->count(), 'warehouseLowStockCount'=>$lowStockProducts->count(),
            'warehouseOutOfStockCount'=>$outOfStockProducts->count(), 'warehouseSafeStockCount'=>$safeStockCount,
            'expiredBatchCount'=>$expiredBatchCount, 'expiringBatchCount'=>$expiringBatchCount,
            'warehousePriorities'=>$priorities->take(8), 'warehousePriorityCount'=>$priorities->count(),
            'draftOpnames'=>$draftOpnames, 'opnameQueue'=>$opnameQueue, 'pendingReceipts'=>$pendingReceipts, 'pendingReceiptCount'=>$pendingReceiptCount,
            'stockedLocations'=>$stockedLocations, 'recentMovements'=>$recentMovements,
            'transfersToday'=>StockTransfer::whereDate('transfer_date',$today)->count(),
        ]);
    }
}
