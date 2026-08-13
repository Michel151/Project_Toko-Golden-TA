<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\Request;

class StockMovementController extends Controller
{
    public function index(Request $request)
    {
        $query = StockMovement::with(['product.buyUnit', 'product.retailUnit', 'location', 'createdBy'])
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->product_id))
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->location_id))
            ->when($request->filled('movement_type'), fn ($q) => $q->where('movement_type', $request->movement_type))
            ->when($request->filled('direction'), function ($q) use ($request) {
                $request->direction === 'in' ? $q->where('quantity', '>', 0) : $q->where('quantity', '<', 0);
            })
            ->when($request->filled('from'), fn ($q) => $q->whereDate('movement_date', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('movement_date', '<=', $request->to));

        $summaryQuery = clone $query;
        $totalIn = (clone $summaryQuery)->where('quantity', '>', 0)->count();
        $totalOut = (clone $summaryQuery)->where('quantity', '<', 0)->count();
        $movements = $query->latest('movement_date')->latest('id')->paginate(25)->withQueryString();

        $products = Product::orderBy('name')->get(['id', 'name']);
        $locations = Location::orderBy('name')->get(['id', 'name']);

        return view('inventory.opnames.movements', compact(
            'movements', 'products', 'locations', 'totalIn', 'totalOut'
        ));
    }
}
