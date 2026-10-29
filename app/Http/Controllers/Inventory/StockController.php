<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockBatch;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'view' => 'nullable|in:stock,priorities',
            'status' => 'nullable|in:low,out,safe',
            'expiry_status' => 'nullable|in:expired,expiring,safe,without_date,untracked',
            'location_id' => 'nullable|exists:locations,id',
            'category_id' => 'nullable|exists:categories,id',
            'search' => 'nullable|string|max:200',
        ]);
        $locations  = Location::orderBy('name')->get();
        $categories = \App\Models\Category::orderBy('name')->get();

        $allProducts = Product::with(['costBalance', 'stocks', 'stockBatches', 'productUnits.unit'])->get();
        $totalProducts = $allProducts->count();
        $lowStockCount = $allProducts->filter(fn ($product) => $product->stockAvailability()['sellable'] > 0 && $product->stockAvailability()['sellable'] <= (float) $product->min_stock_total)->count();
        $outOfStockCount = $allProducts->filter(fn ($product) => $product->stockAvailability()['sellable'] <= 0)->count();
        $expiredBatchCount = StockBatch::where('quantity_remaining', '>', 0)
            ->whereDate('expired_date', '<', today())
            ->count();
        $expiringBatchCount = StockBatch::where('quantity_remaining', '>', 0)
            ->whereDate('expired_date', '>=', today())
            ->whereDate('expired_date', '<=', today()->addDays(30))
            ->count();
        $inventoryValue = $allProducts->sum(function ($product) {
            return $product->inventoryValue();
        });

        $query = Product::with([
            'buyUnit',
            'retailUnit',
            'category',
            'stocks.location',
            'productLocations',
            'productUnits.unit',
            'stockBatches' => fn ($query) => $query
                ->with('location')
                ->where('quantity_remaining', '>', 0)
                ->orderByRaw('expired_date IS NULL')
                ->orderBy('expired_date')
                ->orderBy('received_date'),
        ]);

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->filled('location_id')) {
            $query->whereHas('stocks', function ($q) use ($request) {
                $q->where('location_id', $request->location_id)
                  ->where('quantity', '>', 0);
            });
        }
        if ($request->filled('expiry_status')) {
            $batchFilter = function ($q) use ($request) {
                $q->where('quantity_remaining', '>', 0);

                if ($request->filled('location_id')) {
                    $q->where('location_id', $request->location_id);
                }

                if ($request->expiry_status === 'expired') {
                    $q->whereDate('expired_date', '<', today());
                } elseif ($request->expiry_status === 'expiring') {
                    $q->whereDate('expired_date', '>=', today())
                        ->whereDate('expired_date', '<=', today()->addDays(30));
                } elseif ($request->expiry_status === 'safe') {
                    $q->whereDate('expired_date', '>', today()->addDays(30));
                } elseif ($request->expiry_status === 'without_date') {
                    $q->whereNull('expired_date');
                }
            };

            if (in_array($request->expiry_status, ['expired', 'expiring', 'safe', 'without_date'], true)) {
                $query->whereHas('stockBatches', $batchFilter);

                if ($request->expiry_status === 'safe') {
                    $query->whereDoesntHave('stockBatches', function ($q) use ($request) {
                        $q->where('quantity_remaining', '>', 0)
                            ->whereNotNull('expired_date')
                            ->whereDate('expired_date', '<=', today()->addDays(30));

                        if ($request->filled('location_id')) {
                            $q->where('location_id', $request->location_id);
                        }
                    });
                }
            } elseif ($request->expiry_status === 'untracked') {
                $query->where('is_expirable', false);
            }
        }
        if ($request->filled('status')) {
            $stockTotalSql = '(CASE WHEN products.is_expirable = 1 THEN
                (SELECT COALESCE(SUM(stock_batches.quantity_remaining), 0) FROM stock_batches
                 WHERE stock_batches.product_id = products.id AND stock_batches.quantity_remaining > 0
                 AND (stock_batches.expired_date IS NULL OR stock_batches.expired_date >= ?))
                ELSE (SELECT COALESCE(SUM(stocks.quantity), 0) FROM stocks WHERE stocks.product_id = products.id) END)';
            $bindings = [today()->toDateString()];
            if ($request->status === 'low') {
                $query->whereRaw("{$stockTotalSql} > 0", $bindings)->whereRaw("{$stockTotalSql} <= products.min_stock_total", $bindings);
            } elseif ($request->status === 'out') {
                $query->whereRaw("{$stockTotalSql} <= 0", $bindings);
            } elseif ($request->status === 'safe') {
                $query->whereRaw("{$stockTotalSql} > products.min_stock_total", $bindings);
            }
        }

        $priorityQuery = StockBatch::with(['location', 'product.buyUnit', 'product.retailUnit', 'product.productUnits.unit'])
            ->whereIn('product_id', (clone $query)->select('products.id')->reorder())
            ->whereHas('product', fn ($q) => $q->where('is_expirable', true))
            ->where('quantity_remaining', '>', 0)
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->location_id))
            ->where(fn ($q) => $q->whereNull('expired_date')->orWhereDate('expired_date', '<=', today()->addDays(30)));
        if ($request->filled('expiry_status')) {
            match ($request->expiry_status) {
                'expired' => $priorityQuery->whereDate('expired_date', '<', today()),
                'expiring' => $priorityQuery->whereDate('expired_date', '>=', today())->whereDate('expired_date', '<=', today()->addDays(30)),
                'without_date' => $priorityQuery->whereNull('expired_date'),
                'safe', 'untracked' => $priorityQuery->whereRaw('1 = 0'),
            };
        }
        $priorityCount = (clone $priorityQuery)->count();
        $priorityBatches = $request->view === 'priorities'
            ? $priorityQuery->orderByRaw('expired_date IS NULL')->orderBy('expired_date')->orderBy('id')->paginate(25)->withQueryString()
            : null;
        $products = $query->orderBy('name')->orderBy('id')->paginate(25)->withQueryString();

        return view('inventory.stock.index', compact(
            'products', 'locations', 'categories', 'totalProducts',
            'lowStockCount', 'outOfStockCount', 'inventoryValue',
            'expiredBatchCount', 'expiringBatchCount', 'priorityCount', 'priorityBatches'
        ));
    }
}
