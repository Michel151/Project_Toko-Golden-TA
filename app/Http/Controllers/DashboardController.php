<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Sale;
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
            return $this->cashierDashboard();
        }
        if (auth()->user()->isStafGudang()) {
            return $this->warehouseDashboard();
        }

        $today = today();

        $salesToday = Sale::whereDate('sale_date', $today)->sum('total_amount');
        $salesCount = Sale::whereDate('sale_date', $today)->count();
        $salesYesterday = Sale::whereDate('sale_date', $today->copy()->subDay())->sum('total_amount');
        $dailyGrowth = $salesYesterday > 0
            ? (($salesToday - $salesYesterday) / $salesYesterday) * 100
            : ($salesToday > 0 ? 100 : 0);

        $monthSalesQuery = Sale::whereBetween('sale_date', [
            $today->copy()->startOfMonth(),
            $today->copy()->endOfMonth(),
        ]);
        $salesMonth = (clone $monthSalesQuery)->sum('total_amount');
        $salesMonthCount = (clone $monthSalesQuery)->count();
        $averageTransaction = $salesMonthCount > 0 ? $salesMonth / $salesMonthCount : 0;

        $salesPreviousMonth = Sale::whereBetween('sale_date', [
            $today->copy()->subMonthNoOverflow()->startOfMonth(),
            $today->copy()->subMonthNoOverflow()->endOfMonth(),
        ])->sum('total_amount');
        $monthlyGrowth = $salesPreviousMonth > 0
            ? (($salesMonth - $salesPreviousMonth) / $salesPreviousMonth) * 100
            : ($salesMonth > 0 ? 100 : 0);

        // PO pending
        $pendingPO = PurchaseOrder::whereIn('status', ['pending', 'partial'])->count();

        $totalProducts = Product::count();
        $expiringSoonCount = StockBatch::where('quantity_remaining', '>', 0)
            ->whereDate('expired_date', '>=', $today)
            ->whereDate('expired_date', '<=', $today->copy()->addDays(30))
            ->count();
        $expiredBatchCount = StockBatch::where('quantity_remaining', '>', 0)
            ->whereDate('expired_date', '<', $today)
            ->count();
        $expiringBatches = StockBatch::with(['product', 'location'])
            ->where('quantity_remaining', '>', 0)
            ->whereNotNull('expired_date')
            ->whereDate('expired_date', '<=', $today->copy()->addDays(30))
            ->orderBy('expired_date')
            ->limit(5)
            ->get();

        // Notifikasi stok
        $notifications = $this->notificationService->checkAllProducts();
        $dangerNotifs  = array_filter($notifications, fn($n) => $n['level'] === 'danger');
        $warningNotifs = array_filter($notifications, fn($n) => $n['level'] === 'warning');

        $salesChartRaw = Sale::select(
                DB::raw('DATE(sale_date) as date'),
                DB::raw('SUM(total_amount) as total')
            )
            ->where('sale_date', '>=', today()->subDays(6))
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('total', 'date');

        $salesChart = collect(range(6, 0))->map(function ($daysAgo) use ($today, $salesChartRaw) {
            $date = $today->copy()->subDays($daysAgo);

            return [
                'label' => $date->translatedFormat('d M'),
                'total' => (float) ($salesChartRaw[$date->toDateString()] ?? 0),
            ];
        });

        $products = Product::with(['stocks', 'buyUnit', 'retailUnit', 'category', 'productUnits'])->get();
        $lowStockProducts = $products
            ->filter(fn($product) => (float) $product->stocks->sum('quantity') <= (float) $product->min_stock_total)
            ->take(10);
        $outOfStockCount = $products
            ->filter(fn($product) => (float) $product->stocks->sum('quantity') <= 0)
            ->count();
        $inventoryValue = $products->sum(function ($product) {
            $quantity = (float) $product->stocks->sum('quantity');
            $purchaseUnit = $product->productUnits->firstWhere('is_default_purchase', true);
            $baseCost = $purchaseUnit && (float) $purchaseUnit->conversion_to_base > 0
                ? (float) $purchaseUnit->buy_price / (float) $purchaseUnit->conversion_to_base
                : (float) $product->buy_price;

            return $quantity * $baseCost;
        });

        $topProducts = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->whereDate('sales.sale_date', '>=', $today->copy()->subDays(29))
            ->groupBy('products.id', 'products.name')
            ->orderByDesc(DB::raw('SUM(sale_items.subtotal)'))
            ->limit(5)
            ->get([
                'products.name',
                DB::raw('SUM(sale_items.subtotal) as revenue'),
                DB::raw('SUM(sale_items.quantity) as quantity'),
            ]);

        $paymentMethods = (clone $monthSalesQuery)
            ->select('payment_method', DB::raw('COUNT(*) as total'))
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        $locationValues = DB::table('locations')
            ->leftJoin('stocks', 'stocks.location_id', '=', 'locations.id')
            ->leftJoin('products', 'products.id', '=', 'stocks.product_id')
            ->leftJoin('product_units', function ($join) {
                $join->on('product_units.product_id', '=', 'products.id')
                    ->where('product_units.is_default_purchase', true);
            })
            ->groupBy('locations.id', 'locations.name')
            ->orderByDesc(DB::raw('COALESCE(SUM(stocks.quantity * CASE WHEN product_units.conversion_to_base > 0 THEN product_units.buy_price / product_units.conversion_to_base ELSE products.buy_price END), 0)'))
            ->get([
                'locations.name',
                DB::raw('COALESCE(SUM(stocks.quantity * CASE WHEN product_units.conversion_to_base > 0 THEN product_units.buy_price / product_units.conversion_to_base ELSE products.buy_price END), 0) as value'),
            ]);

        $recentSales = Sale::with('createdBy')
            ->latest('sale_date')
            ->latest('id')
            ->limit(20)
            ->get();

        return view('dashboard.index', compact(
            'salesToday', 'salesCount', 'salesYesterday', 'dailyGrowth',
            'salesMonth', 'salesMonthCount', 'averageTransaction', 'monthlyGrowth',
            'pendingPO', 'totalProducts',
            'expiringSoonCount', 'expiredBatchCount', 'expiringBatches',
            'notifications', 'dangerNotifs', 'warningNotifs',
            'salesChart', 'lowStockProducts', 'outOfStockCount', 'inventoryValue',
            'topProducts', 'paymentMethods', 'locationValues', 'recentSales'
        ));
    }

    private function cashierDashboard()
    {
        $userId = auth()->id();
        $today = today();
        $todayQuery = Sale::where('created_by', $userId)->whereDate('sale_date', $today);
        $monthQuery = Sale::where('created_by', $userId)->whereBetween('sale_date', [
            $today->copy()->startOfMonth(), $today->copy()->endOfMonth(),
        ]);

        $cashierSalesToday = (float) (clone $todayQuery)->sum('total_amount');
        $cashierTransactionsToday = (clone $todayQuery)->count();
        $cashierAverageToday = $cashierTransactionsToday > 0 ? $cashierSalesToday / $cashierTransactionsToday : 0;
        $cashierCashToday = (float) (clone $todayQuery)->where('payment_method', 'tunai')->sum('total_amount');
        $cashierTransferToday = (float) (clone $todayQuery)->where('payment_method', 'transfer')->sum('total_amount');
        $cashierSalesMonth = (float) (clone $monthQuery)->sum('total_amount');
        $cashierTransactionsMonth = (clone $monthQuery)->count();

        $chartRaw = Sale::select(DB::raw('DATE(sale_date) as date'), DB::raw('SUM(total_amount) as total'))
            ->where('created_by', $userId)
            ->where('sale_date', '>=', $today->copy()->subDays(6))
            ->groupBy('date')->pluck('total', 'date');
        $cashierSalesChart = collect(range(6, 0))->map(function ($daysAgo) use ($today, $chartRaw) {
            $date = $today->copy()->subDays($daysAgo);
            return ['label' => $date->translatedFormat('d M'), 'total' => (float) ($chartRaw[$date->toDateString()] ?? 0)];
        });

        $cashierRecentSales = Sale::where('created_by', $userId)
            ->latest('sale_date')->latest('id')->limit(8)->get();

        return view('dashboard.cashier', compact(
            'cashierSalesToday', 'cashierTransactionsToday', 'cashierAverageToday',
            'cashierCashToday', 'cashierTransferToday', 'cashierSalesMonth',
            'cashierTransactionsMonth', 'cashierSalesChart', 'cashierRecentSales'
        ));
    }

    private function warehouseDashboard()
    {
        $products = Product::with(['stocks', 'buyUnit', 'retailUnit', 'category'])->get();
        $outOfStockProducts = $products->filter(fn ($product) => (float) $product->stocks->sum('quantity') <= 0);
        $lowStockProducts = $products->filter(function ($product) {
            $total = (float) $product->stocks->sum('quantity');
            return $total > 0 && $total <= (float) $product->min_stock_total;
        });
        $safeStockCount = $products->count() - $outOfStockProducts->count() - $lowStockProducts->count();

        $draftOpnames = StockOpname::where('status', 'draft')->count();
        $transfersToday = StockTransfer::whereDate('transfer_date', today())->count();
        $transfersMonth = StockTransfer::whereBetween('transfer_date', [now()->startOfMonth(), now()->endOfMonth()])->count();
        $warehouseNotifications = $this->notificationService->checkAllProducts();

        $stockedLocations = Location::withCount([
            'stocks as stocked_products_count' => fn ($query) => $query->where('quantity', '>', 0),
        ])->orderByDesc('stocked_products_count')->get();

        $recentMovements = StockMovement::with(['product.buyUnit', 'product.retailUnit', 'location', 'createdBy'])
            ->latest('movement_date')->latest('id')->limit(10)->get();

        return view('dashboard.warehouse', [
            'warehouseProductCount' => $products->count(),
            'warehouseLowStockCount' => $lowStockProducts->count(),
            'warehouseOutOfStockCount' => $outOfStockProducts->count(),
            'warehouseSafeStockCount' => $safeStockCount,
            'warehouseLowStockProducts' => $lowStockProducts->merge($outOfStockProducts)->take(8),
            'draftOpnames' => $draftOpnames,
            'transfersToday' => $transfersToday,
            'transfersMonth' => $transfersMonth,
            'warehouseNotifications' => $warehouseNotifications,
            'stockedLocations' => $stockedLocations,
            'recentMovements' => $recentMovements,
        ]);
    }
}
