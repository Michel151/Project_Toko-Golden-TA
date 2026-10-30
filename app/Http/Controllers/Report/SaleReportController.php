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
            'margins_sort'=>'nullable|in:revenue,margin_desc,margin_asc,profit_desc,profit_asc',
        ]);
        $productsSort = $filters['products_sort'] ?? 'revenue';
        $marginsSort = $filters['margins_sort'] ?? 'revenue';
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
            })->sort(function ($a, $b) use ($marginsSort) {
                $field = match ($marginsSort) {
                    'margin_desc', 'margin_asc' => 'margin_percentage',
                    'profit_desc', 'profit_asc' => 'gross_profit',
                    default => 'revenue',
                };
                // Incomplete costs and undefined percentages always follow known values.
                if (($a->$field === null) !== ($b->$field === null)) return $a->$field === null ? 1 : -1;
                $comparison = in_array($marginsSort, ['margin_asc', 'profit_asc'], true)
                    ? $a->$field <=> $b->$field : $b->$field <=> $a->$field;
                return $comparison ?: (($b->revenue <=> $a->revenue) ?: ($a->product_id <=> $b->product_id));
            })->values();

        // Prefer the historical quantity snapshot; legacy rows fall back to their unit conversion.
        $baseQuantity = \App\Support\SaleQuantity::baseQuantitySql();
        $perPage = (int) ($filters['per_page'] ?? 25);
        $topProducts = SaleItem::select('sale_items.product_id', DB::raw("SUM({$baseQuantity}) as total_qty"), DB::raw('SUM(sale_items.subtotal) as total_amount'))
            ->leftJoin('product_units', 'product_units.id', '=', 'sale_items.product_unit_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->when($categoryId, fn ($query) => $query->where('products.category_id', $categoryId))
            ->whereHas('sale', fn($q) => $q->paid()->whereDate('sale_date', '>=', $from)->whereDate('sale_date', '<=', $to))
            ->with(['product.productUnits.unit', 'product.buyUnit', 'product.retailUnit'])
            ->groupBy('sale_items.product_id')
            ->get();
        $topProducts->each(function ($item) {
            $product = $item->product;
            $units = $product?->productUnits ?? collect();
            $mainUnit = $units->firstWhere('is_default_sale', true)
                ?? $units->firstWhere('unit_id', $product?->buy_unit_id)
                ?? $units->firstWhere('is_base_unit', true);
            $conversion = (float) ($mainUnit?->conversion_to_base ?? 0);
            $item->package_quantity = $conversion > 0 ? (float) $item->total_qty / $conversion : null;
            $item->package_unit_id = $mainUnit?->unit_id;
            $item->package_unit = $mainUnit?->unit?->name;
            $item->display_quantity = 'Satuan jual belum tersedia';
            if ($conversion > 0) {
                // Format from the main sale unit down without changing the shared product's units.
                $displayProduct = clone $product;
                $displayProduct->setRelation('productUnits', $units->filter(fn ($unit) => $unit->id === $mainUnit->id
                    || ((float) $unit->conversion_to_base > 0 && (float) $unit->conversion_to_base < $conversion)));
                $item->display_quantity = $displayProduct->displayStock((float) $item->total_qty);
            }
        });
        $topProducts = $topProducts->sort(function ($a, $b) use ($productsSort) {
            if ($productsSort === 'quantity') {
                $comparison = ($b->package_quantity ?? -1) <=> ($a->package_quantity ?? -1);
                if ($comparison) return $comparison;
            }
            return ($b->total_amount <=> $a->total_amount) ?: ($a->product_id <=> $b->product_id);
        })->values();
        $productsPage = min((int) ($filters['products_page'] ?? 1), max(1, (int) ceil($topProducts->count() / $perPage)));
        $topProducts = (new LengthAwarePaginator($topProducts->forPage($productsPage, $perPage)->values(),
            $topProducts->count(), $perPage, $productsPage,
            ['path'=>LengthAwarePaginator::resolveCurrentPath(), 'pageName'=>'products_page']))
            ->withQueryString()->appends(['tab'=>'products'])->fragment('saleReportTabs');

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
            'topProducts', 'activeTab', 'productsSort', 'marginsSort', 'categories', 'category', 'categoryId'
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
