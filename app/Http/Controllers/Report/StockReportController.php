<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Category;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\ReportExport;
use Maatwebsite\Excel\Facades\Excel;

class StockReportController extends Controller
{
    public function index(Request $request)
    {
        $locations  = Location::orderBy('name')->get();
        $categories = Category::orderBy('name')->get();

        $query = Product::with(['buyUnit', 'retailUnit', 'category', 'stocks.location', 'productLocations.location', 'productUnits.unit']);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->filled('location_id')) {
            $query->whereHas('stocks', fn($q) =>
                $q->where('location_id', $request->location_id)
            );
        }

        $products = $query->orderBy('name')->get();

        return view('report.stock', compact('products', 'locations', 'categories'));
    }

    public function pdf(Request $request)
    {
        $query = Product::with(['buyUnit', 'retailUnit', 'category', 'stocks.location', 'productUnits.unit']);
        if ($request->filled('category_id')) $query->where('category_id', $request->category_id);
        if ($request->filled('location_id')) {
            $query->whereHas('stocks', fn ($q) => $q->where('location_id', $request->location_id));
        }
        $products = $query->orderBy('name')->get();
        $pdf = Pdf::loadView('report.pdf.stock', compact('products'))
                  ->setPaper('a4', 'landscape');
        return $pdf->stream('Laporan-Stok-' . date('Y-m-d') . '.pdf');
    }

    public function excel(Request $request)
    {
        $locations = Location::orderBy('name')->get();
        $query = Product::with(['buyUnit', 'retailUnit', 'category', 'stocks', 'productUnits.unit']);
        if ($request->filled('category_id')) $query->where('category_id', $request->category_id);
        if ($request->filled('location_id')) {
            $query->whereHas('stocks', fn ($q) => $q->where('location_id', $request->location_id));
        }
        $products = $query->orderBy('name')->get();

        $headings = ['Barang', 'Kategori', 'Satuan Stok', ...$locations->pluck('name')->all(), 'Total Stok', 'Min. Stok', 'Nilai Stok', 'Status'];
        $rows = $products->map(function ($product) use ($locations) {
            $total = (float) $product->stocks->sum('quantity');
            $value = $product->can_retail && (float) $product->conversion_factor > 0
                ? ($total / (float) $product->conversion_factor) * (float) $product->buy_price
                : $total * (float) $product->buy_price;
            $locationStocks = $locations->map(fn ($location) => (float) ($product->stocks->firstWhere('location_id', $location->id)?->quantity ?? 0));

            return [
                $product->name,
                $product->category->name ?? '-',
                $product->can_retail ? ($product->retailUnit->name ?? $product->buyUnit->name) : $product->buyUnit->name,
                ...$locationStocks->all(),
                $total,
                (float) $product->min_stock_total,
                $value,
                $total <= (float) $product->min_stock_total ? 'Rendah' : 'Aman',
            ];
        })->all();

        $valueColumn = count($headings) - 1;
        $decimalColumns = range(4, 5 + $locations->count());
        return Excel::download(
            new ReportExport('Laporan Stok Toko Golden', $headings, $rows, [$valueColumn], $decimalColumns, 'Stok'),
            'Laporan-Stok-' . date('Y-m-d') . '.xlsx'
        );
    }
}
