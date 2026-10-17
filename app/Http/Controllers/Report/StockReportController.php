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
    private const EXPIRY_FILTERS = [
        'tracked' => 'Barang Berkedaluwarsa',
        'expired' => 'Sudah Kedaluwarsa',
        'expiring_soon' => 'Hampir Kedaluwarsa (30 Hari)',
        'safe' => 'Aman dari Kedaluwarsa',
    ];

    public function index(Request $request)
    {
        $locations  = Location::orderBy('name')->get();
        $categories = Category::orderBy('name')->get();

        $query = Product::with(['buyUnit', 'retailUnit', 'category', 'stocks.location', 'stockBatches', 'productLocations.location', 'productUnits.unit']);

        $this->applyFilters($query, $request);

        $products = $query->orderBy('name')->get();
        $expiryFilters = self::EXPIRY_FILTERS;
        $expiryFilterLabel = $this->expiryFilterLabel($request);
        $stockSummary = ['products'=>$products->count(), 'value'=>0.0, 'low'=>0, 'empty'=>0];
        foreach ($products as $product) {
            $availability = $product->stockAvailability();
            $stockSummary['value'] += $availability['physical'] * $this->baseCost($product);
            if ($availability['sellable'] <= 0) {
                $stockSummary['empty']++;
            } elseif ($availability['sellable'] <= (float) $product->min_stock_total) {
                $stockSummary['low']++;
            }
        }

        return view('report.stock', compact('products', 'locations', 'categories', 'expiryFilters', 'expiryFilterLabel', 'stockSummary'));
    }

    public function pdf(Request $request)
    {
        $query = Product::with(['buyUnit', 'retailUnit', 'category', 'stocks.location', 'stockBatches', 'productUnits.unit']);
        $this->applyFilters($query, $request);
        $products = $query->orderBy('name')->get();
        $reportTitle = $this->reportTitle($request);
        $expiryFilterLabel = $this->expiryFilterLabel($request);
        $reportMetadata = $this->exportMetadata($request);
        $pdf = Pdf::loadView('report.pdf.stock', compact('products', 'reportTitle', 'expiryFilterLabel', 'reportMetadata'))
                  ->setPaper('a4', 'landscape');
        return $pdf->stream($this->fileName($request, 'pdf'));
    }

    public function excel(Request $request)
    {
        $locations = Location::orderBy('name')->get();
        $query = Product::with(['buyUnit', 'retailUnit', 'category', 'stocks', 'stockBatches', 'productUnits.unit']);
        $this->applyFilters($query, $request);
        $products = $query->orderBy('name')->get();

        $headings = ['Barang', 'Kategori', 'Satuan Dasar', ...$locations->pluck('name')->all(), 'Total Fisik', 'Layak Jual', 'Kedaluwarsa', 'Min. Stok', 'Nilai Stok', 'Status'];
        $rows = $products->map(function ($product) use ($locations) {
            $total = (float) $product->stocks->sum('quantity');
            $sellable = $product->is_expirable
                ? (float) $product->stockBatches
                    ->filter(fn ($batch) => (float) $batch->quantity_remaining > 0 && (! $batch->expired_date || $batch->expired_date->gte(today())))
                    ->sum(fn ($batch) => (float) $batch->quantity_remaining)
                : $total;
            $expired = $product->is_expirable
                ? (float) $product->stockBatches
                    ->filter(fn ($batch) => (float) $batch->quantity_remaining > 0 && $batch->expired_date && $batch->expired_date->lt(today()))
                    ->sum(fn ($batch) => (float) $batch->quantity_remaining)
                : 0;
            $value = $total * $this->baseCost($product);
            $locationStocks = $locations->map(function ($location) use ($product) {
                if (! $product->is_expirable) {
                    return (float) ($product->stocks->firstWhere('location_id', $location->id)?->quantity ?? 0);
                }

                return (float) $product->stockBatches
                    ->where('location_id', $location->id)
                    ->filter(fn ($batch) => (float) $batch->quantity_remaining > 0 && (! $batch->expired_date || $batch->expired_date->gte(today())))
                    ->sum(fn ($batch) => (float) $batch->quantity_remaining);
            });

            return [
                $product->name,
                $product->category->name ?? '-',
                $product->productUnits->firstWhere('is_base_unit', true)?->unit?->name
                    ?? ($product->can_retail ? ($product->retailUnit->name ?? $product->buyUnit->name) : $product->buyUnit->name),
                ...$locationStocks->map(fn ($quantity) => $product->displayStock($quantity))->all(),
                $product->displayStock($total),
                $product->displayStock($sellable),
                $product->displayStock($expired),
                $product->displayStock((float) $product->min_stock_total),
                $value,
                $sellable <= (float) $product->min_stock_total ? 'Rendah' : 'Aman',
            ];
        })->all();

        $valueColumn = count($headings) - 1;
        return Excel::download(
            new ReportExport($this->reportTitle($request), $headings, $rows, [$valueColumn], [], 'Stok', $this->exportMetadata($request)),
            $this->fileName($request, 'xlsx')
        );
    }

    private function applyFilters($query, Request $request): void
    {
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('location_id')) {
            $query->whereHas('stocks', fn ($q) => $q->where('location_id', $request->location_id));
        }

        match ($request->input('expiry_status')) {
            'tracked' => $query->where('is_expirable', true)
                ->whereHas('stockBatches', fn ($q) => $q
                    ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->location_id))
                    ->where('quantity_remaining', '>', 0)
                    ->whereNotNull('expired_date')),
            'expired' => $query->where('is_expirable', true)
                ->whereHas('stockBatches', fn ($q) => $q
                    ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->location_id))
                    ->where('quantity_remaining', '>', 0)
                    ->whereDate('expired_date', '<', today())),
            'expiring_soon' => $query->where('is_expirable', true)
                ->whereHas('stockBatches', fn ($q) => $q
                    ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->location_id))
                    ->where('quantity_remaining', '>', 0)
                    ->whereBetween('expired_date', [today(), today()->addDays(30)])),
            'safe' => $query->where('is_expirable', true)
                ->whereHas('stockBatches', fn ($q) => $q
                    ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->location_id))
                    ->where('quantity_remaining', '>', 0)
                    ->whereDate('expired_date', '>', today()->addDays(30))),
            default => null,
        };
    }

    private function exportMetadata(Request $request): array
    {
        return array_values(array_filter([
            $request->filled('category_id') ? 'Kategori: '.(Category::find($request->category_id)?->name ?? '-') : null,
            $request->filled('location_id') ? 'Seleksi barang di lokasi: '.(Location::find($request->location_id)?->name ?? '-').' (jumlah stok seluruh lokasi)' : 'Cakupan: seluruh lokasi',
            $this->expiryFilterLabel($request) ? 'Kedaluwarsa: '.$this->expiryFilterLabel($request) : null,
        ]));
    }

    private function expiryFilterLabel(Request $request): ?string
    {
        return self::EXPIRY_FILTERS[$request->input('expiry_status')] ?? null;
    }

    private function reportTitle(Request $request): string
    {
        return $this->expiryFilterLabel($request)
            ? 'Laporan Kedaluwarsa Barang Toko Golden'
            : 'Laporan Stok Toko Golden';
    }

    private function fileName(Request $request, string $extension): string
    {
        $prefix = $this->expiryFilterLabel($request) ? 'Laporan-Kedaluwarsa-Barang' : 'Laporan-Stok';

        return $prefix . '-' . now()->format('Y-m-d') . '.' . $extension;
    }

    private function baseCost(Product $product): float
    {
        $purchaseUnit = $product->productUnits->firstWhere('is_default_purchase', true);

        if ($purchaseUnit && (float) $purchaseUnit->conversion_to_base > 0) {
            return (float) $purchaseUnit->buy_price / (float) $purchaseUnit->conversion_to_base;
        }

        return (float) $product->buy_price;
    }
}
