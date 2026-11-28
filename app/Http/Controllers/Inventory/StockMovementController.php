<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use App\Support\StockMovementReferenceLinks;
use Illuminate\Http\Request;

class StockMovementController extends Controller
{
    // Menampilkan catatan perubahan stok sesuai barang, lokasi, arah, dan periode.
    public function index(Request $request)
    {
        ['from'=>$from, 'to'=>$to] = \App\Support\MonthlyDateRange::fromRequest($request);
        $query = StockMovement::with(['product.productUnits.unit', 'product.buyUnit', 'product.retailUnit', 'location', 'createdBy'])
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->product_id))
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->location_id))
            ->when($request->filled('movement_type'), fn ($q) => $q->where('movement_type', $request->movement_type))
            ->when($request->filled('direction'), function ($q) use ($request) {
                $request->direction === 'in' ? $q->where('quantity', '>', 0) : $q->where('quantity', '<', 0);
            })
            ->when($from, fn ($q) => $q->whereDate('movement_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('movement_date', '<=', $to));

        // Menghitung jumlah catatan masuk dan keluar sesuai filter, bukan menjumlahkan kuantitas barang.
        $summaryQuery = clone $query;
        $totalIn = (clone $summaryQuery)->where('quantity', '>', 0)->count();
        $totalOut = (clone $summaryQuery)->where('quantity', '<', 0)->count();
        // Menampilkan 25 catatan terbaru dan mempertahankan filter saat pindah halaman.
        $movements = $query->latest('movement_date')->latest('id')->paginate(25)->withQueryString()
            ->appends(['from'=>$from ?? '', 'to'=>$to ?? '']);
        StockMovementReferenceLinks::attach($movements->getCollection(), $request->user());

        $products = Product::orderBy('name')->get(['id', 'name']);
        $locations = Location::orderBy('name')->get(['id', 'name']);

        return view('inventory.opnames.movements', compact(
            'movements', 'products', 'locations', 'totalIn', 'totalOut', 'from', 'to'
        ));
    }
}
