<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\ReportExport;
use Maatwebsite\Excel\Facades\Excel;

class SaleReportController extends Controller
{
    private function categoryId(Request $request): ?int
    {
        $data = $request->validate(['category_id'=>'nullable|integer|exists:categories,id']);
        return isset($data['category_id']) ? (int) $data['category_id'] : null;
    }

    private function periodSales(string $from, string $to, ?int $categoryId)
    {
        $sales = Sale::with(['createdBy', 'items'=>fn ($query) => $query->with('product')
            ->when($categoryId, fn ($query) => $query->whereHas('product', fn ($products) => $products->where('category_id', $categoryId)))])
            ->paid()->whereDate('sale_date', '>=', $from)->whereDate('sale_date', '<=', $to)
            ->when($categoryId, fn ($query) => $query->whereHas('items.product', fn ($products) => $products->where('category_id', $categoryId)))
            ->latest('sale_date')->latest('created_at')->latest('id')->get();
        foreach ($sales as $sale) {
            // Report-only subtotal; never overwrite or save the invoice's original amount.
            $sale->setAttribute('report_total_amount', $categoryId ? $sale->items->sum('subtotal') : $sale->total_amount);
        }
        return $sales;
    }

    public function index(Request $request)
    {
        $categoryId = $this->categoryId($request);
        $categories = Category::orderBy('name')->get(['id', 'name']);
        $category = $categories->firstWhere('id', $categoryId);
        $filters = $request->validate([
            'per_page'=>'nullable|integer|in:25,50,100', 'tab'=>'nullable|in:transactions,products,margins',
            'products_sort'=>'nullable|in:revenue,quantity', 'products_page'=>'nullable|integer|min:1',
            'margins_page'=>'nullable|integer|min:1',
        ]);
        $productsSort = $filters['products_sort'] ?? 'revenue';
        $activeTab = $filters['tab'] ?? 'transactions';
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to   = $request->get('to', now()->toDateString());

        $sales = $this->periodSales($from, $to, $categoryId);

        $totalAmount   = $sales->sum('report_total_amount');
        $totalTunai    = $sales->where('payment_method', 'tunai')->sum('report_total_amount');
        $totalTransfer = $sales->where('payment_method', 'transfer')->sum('report_total_amount');
        $totalTrx      = $sales->count();
        $totalCost = (float) $sales->sum(fn ($sale) => $sale->items->sum('cost_subtotal'));
        $hasIncompleteCost = fn ($item) => $item->cost_subtotal === null || (float) $item->cost_subtotal < 0
            || ((float) $item->cost_subtotal === 0.0 && $item->cost_method !== 'moving_average');
        $incompleteCostCount = $sales->filter(fn ($sale) => $sale->items->isEmpty() || $sale->items->contains($hasIncompleteCost))->count();
        $grossProfit = $incompleteCostCount ? null : round((float) $totalAmount - $totalCost, 2);
        $productMargins = $sales->flatMap(fn ($sale) => $sale->items)->groupBy('product_id')
            ->map(function ($items) use ($hasIncompleteCost) {
                $revenue = (float) $items->sum('subtotal');
                $incomplete = $items->contains($hasIncompleteCost);
                $cost = $incomplete ? null : (float) $items->sum('cost_subtotal');
                $profit = $incomplete ? null : round($revenue - $cost, 2);

                return (object) [
                    'product_id' => $items->first()->product_id,
                    'name' => $items->first()->product?->name ?? 'Barang tidak tersedia',
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'gross_profit' => $profit,
                    'margin_percentage' => $profit !== null && $revenue > 0 ? round($profit / $revenue * 100, 2) : null,
                ];
            })->sortBy([['revenue', 'desc'], ['product_id', 'asc']])->values();

        // Prefer the historical quantity snapshot; legacy rows fall back to their unit conversion.
        $sourceQuantity = '(SELECT CASE WHEN COUNT(*) = COUNT(base_quantity) THEN SUM(base_quantity) ELSE NULL END FROM sale_item_sources WHERE sale_item_sources.sale_item_id = sale_items.id)';
        $baseQuantity = "COALESCE(sale_items.cost_base_quantity, {$sourceQuantity}, sale_items.quantity * COALESCE(product_units.conversion_to_base, CASE WHEN sale_items.sale_type = 'grosir' AND products.can_retail = 1 THEN products.conversion_factor ELSE 1 END))";
        $perPage = (int) ($filters['per_page'] ?? 25);
        $topProducts = SaleItem::select('sale_items.product_id', DB::raw("SUM({$baseQuantity}) as total_qty"), DB::raw('SUM(sale_items.subtotal) as total_amount'))
            ->leftJoin('product_units', 'product_units.id', '=', 'sale_items.product_unit_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->when($categoryId, fn ($query) => $query->where('products.category_id', $categoryId))
            ->whereHas('sale', fn($q) => $q->paid()->whereDate('sale_date', '>=', $from)->whereDate('sale_date', '<=', $to))
            ->with(['product.productUnits.unit', 'product.buyUnit', 'product.retailUnit'])
            ->groupBy('sale_items.product_id')
            ->orderByDesc($productsSort === 'quantity' ? 'total_qty' : 'total_amount')
            ->when($productsSort === 'quantity', fn ($query) => $query->orderByDesc('total_amount'))
            ->orderBy('sale_items.product_id')
            ->paginate($perPage, ['*'], 'products_page')->withQueryString()
            ->appends(['tab'=>'products'])->fragment('saleReportTabs');

        $page = LengthAwarePaginator::resolveCurrentPage();
        $sales = (new LengthAwarePaginator(
            $sales->forPage($page, $perPage)->values(), $sales->count(), $perPage, $page,
            ['path'=>LengthAwarePaginator::resolveCurrentPath()]
        ))->withQueryString();

        $marginPage = LengthAwarePaginator::resolveCurrentPage('margins_page');
        $productMargins = (new LengthAwarePaginator(
            $productMargins->forPage($marginPage, $perPage)->values(), $productMargins->count(), $perPage, $marginPage,
            ['path'=>LengthAwarePaginator::resolveCurrentPath(), 'pageName'=>'margins_page']
        ))->withQueryString()->appends(['tab'=>'margins'])->fragment('saleReportTabs');

        return view('report.sale', compact(
            'sales', 'from', 'to',
            'totalAmount', 'totalTunai', 'totalTransfer', 'totalTrx',
            'grossProfit', 'incompleteCostCount', 'productMargins',
            'topProducts', 'activeTab', 'productsSort', 'categories', 'category', 'categoryId'
        ));
    }

    public function pdf(Request $request)
    {
        $categoryId = $this->categoryId($request);
        $from  = $request->get('from', now()->startOfMonth()->toDateString());
        $to    = $request->get('to', now()->toDateString());
        $sales = $this->periodSales($from, $to, $categoryId);
        $category = $categoryId ? Category::find($categoryId) : null;

        $pdf = Pdf::loadView('report.pdf.sale', compact('sales', 'from', 'to', 'category'))
                  ->setPaper('a4', 'portrait');
        return $pdf->stream("Laporan-Penjualan-{$from}-sd-{$to}.pdf");
    }

    public function excel(Request $request)
    {
        $categoryId = $this->categoryId($request);
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to = $request->get('to', now()->toDateString());
        $sales = $this->periodSales($from, $to, $categoryId);
        $category = $categoryId ? Category::find($categoryId) : null;
        $rows = $sales->map(fn ($sale) => [
            $sale->sale_number, $sale->sale_date->format('d/m/Y'), $sale->customer_name ?? 'Umum',
            ucfirst($sale->payment_method), $sale->createdBy->name ?? '-', (float) $sale->report_total_amount,
        ])->all();

        return Excel::download(
            new ReportExport("Laporan Penjualan {$from} s.d. {$to}", ['No. Invoice', 'Tanggal', 'Pelanggan', 'Pembayaran', 'Kasir', $category ? 'Total Kategori' : 'Total'], $rows, [6], [], 'Penjualan', $category ? ['Kategori: '.$category->name, 'Nominal hanya mencakup barang dalam kategori terpilih.'] : []),
            "Laporan-Penjualan-{$from}-sd-{$to}.xlsx"
        );
    }
}
