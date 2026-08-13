<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function index(Request $request)
    {
        $locations  = Location::orderBy('name')->get();
        $categories = \App\Models\Category::orderBy('name')->get();

        $allProducts = Product::with(['stocks', 'productUnits.unit'])->get();
        $totalProducts = $allProducts->count();
        $lowStockCount = $allProducts->filter(fn ($product) => (float) $product->stocks->sum('quantity') <= (float) $product->min_stock_total)->count();
        $outOfStockCount = $allProducts->filter(fn ($product) => (float) $product->stocks->sum('quantity') <= 0)->count();
        $inventoryValue = $allProducts->sum(function ($product) {
            $quantity = (float) $product->stocks->sum('quantity');
            $purchaseUnit = $product->productUnits->firstWhere('is_default_purchase', true);
            $baseCost = $purchaseUnit && (float) $purchaseUnit->conversion_to_base > 0
                ? (float) $purchaseUnit->buy_price / (float) $purchaseUnit->conversion_to_base
                : (float) $product->buy_price;

            return $quantity * $baseCost;
        });

        $query = Product::with(['buyUnit', 'retailUnit', 'category', 'stocks.location', 'productLocations', 'productUnits.unit']);

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
        if ($request->filled('status')) {
            $stockTotalSql = '(SELECT COALESCE(SUM(stocks.quantity), 0) FROM stocks WHERE stocks.product_id = products.id)';
            if ($request->status === 'low') {
                $query->whereRaw("{$stockTotalSql} <= products.min_stock_total");
            } elseif ($request->status === 'out') {
                $query->whereRaw("{$stockTotalSql} <= 0");
            } elseif ($request->status === 'safe') {
                $query->whereRaw("{$stockTotalSql} > products.min_stock_total");
            }
        }

        $products = $query->orderBy('name')->paginate(25)->withQueryString();

        return view('inventory.stock.index', compact(
            'products', 'locations', 'categories', 'totalProducts',
            'lowStockCount', 'outOfStockCount', 'inventoryValue'
        ));
    }
}
