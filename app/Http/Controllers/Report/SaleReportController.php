<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\Category;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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

    private function cashier(Request $request): ?User
    {
        if (! $request->user()->isOwner()) return null;
        $data = $request->validate(['cashier_id' => 'nullable|integer|exists:users,id']);
        return isset($data['cashier_id']) ? User::find($data['cashier_id']) : null;
    }

    private function periodSales(string $from, string $to, ?int $categoryId, ?int $cashierId)
    {
        $sales = Sale::with(['createdBy', 'items'=>fn ($query) => $query->with('product')
            ->when($categoryId, fn ($query) => $query->whereHas('product', fn ($products) => $products->where('category_id', $categoryId)))])
            ->paid()->whereDate('sale_date', '>=', $from)->whereDate('sale_date', '<=', $to)
            ->when($cashierId, fn ($query) => $query->where('created_by', $cashierId))
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
        $cashier = $this->cashier($request);
        $cashierId = $cashier?->id;
        $cashiers = $request->user()->isOwner()
            ? User::where(fn ($users) => $users->whereIn('role', ['owner', 'kasir'])->orWhereHas('sales'))
                ->orderBy('name')->orderBy('id')->get(['id', 'name', 'deactivated_at'])
            : collect();
        $categories = Category::orderBy('name')->get(['id', 'name']);
        $category = $categories->firstWhere('id', $categoryId);
        $filters = $request->validate([
            'per_page'=>'nullable|integer|in:25,50,100', 
            'tab'=>'nullable|in:transactions,products,margins',
            'margins_page'=>'nullable|integer|min:1',
            'margins_sort'=>'nullable|in:revenue,margin_desc,margin_asc,profit_desc,profit_asc',
        ]);
        $marginsSort = $filters['margins_sort'] ?? 'revenue';
        $activeTab = $this->exportTab($request);
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to   = $request->get('to', now()->toDateString());

        $sales = $this->periodSales($from, $to, $categoryId, $cashierId);

        $totalAmount   = $sales->sum('report_total_amount');
        $totalTunai    = $sales->where('payment_method', 'tunai')->sum('report_total_amount');
        $totalTransfer = $sales->where('payment_method', 'transfer')->sum('report_total_amount');
        $totalTrx      = $sales->count();
        $totalCost = (float) $sales->sum(fn ($sale) => $sale->items->sum('cost_subtotal'));
        $hasIncompleteCost = fn ($item) => $item->cost_subtotal === null || (float) $item->cost_subtotal < 0
            || ((float) $item->cost_subtotal === 0.0 && $item->cost_method !== 'moving_average');
        $incompleteCostCount = $sales->filter(fn ($sale) => $sale->items->isEmpty() || $sale->items->contains($hasIncompleteCost))->count();
        $grossProfit = $incompleteCostCount ? null : round((float) $totalAmount - $totalCost, 2);
        $productMargins = $this->productMargins($sales, $marginsSort);

        $perPage = (int) ($filters['per_page'] ?? 25);
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
            'activeTab', 'marginsSort', 'categories', 'category', 'categoryId',
            'cashier', 'cashierId', 'cashiers'
        ));
    }

    private function productMargins($sales, string $marginsSort)
    {
        $hasIncompleteCost = fn ($item) => $item->cost_subtotal === null || (float) $item->cost_subtotal < 0
            || ((float) $item->cost_subtotal === 0.0 && $item->cost_method !== 'moving_average');
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
        return $productMargins;
    }

    private function exportTab(Request $request): string
    {
        $tab = $request->validate(['tab'=>'nullable|in:transactions,products,margins'])['tab'] ?? 'transactions';
        // Old bookmarks for the retired tab open the transaction report.
        return $tab === 'products' ? 'transactions' : $tab;
    }

    private function exportMarginsData(Request $request): array
    {
        $filters = $request->validate(['margins_sort'=>'nullable|in:revenue,margin_desc,margin_asc,profit_desc,profit_asc']);
        $categoryId = $this->categoryId($request);
        $cashier = $this->cashier($request);
        $category = $categoryId ? Category::find($categoryId) : null;
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to = $request->get('to', now()->toDateString());
        $metadata = $category ? ['Kategori: '.$category->name] : [];
        if ($cashier) $metadata[] = 'Kasir: '.$cashier->name;
        $sort = $filters['margins_sort'] ?? 'revenue';
        $sortLabel = ['revenue'=>'Omzet Tertinggi', 'margin_desc'=>'Margin Tertinggi (%)', 'margin_asc'=>'Margin Terendah (%)',
            'profit_desc'=>'Laba Kotor Tertinggi (Rp)', 'profit_asc'=>'Laba Kotor Terendah (Rp)'][$sort];
        $title = 'Laporan Margin per Produk';
        $filePrefix = 'Laporan-Margin-per-Produk';
        $sheetTitle = 'Margin per Produk';
        $headings = ['Barang', 'Omzet', 'HPP', 'Laba Kotor', 'Margin (%)'];
        $currencyColumns = [2, 3, 4];
        $decimalColumns = [5];
        $rows = $this->productMargins($this->periodSales($from, $to, $categoryId, $cashier?->id), $sort)
            ->map(fn ($item) => [$item->name, $item->revenue, $item->cost ?? 'Belum lengkap',
                $item->gross_profit ?? 'Belum lengkap', $item->cost === null ? 'Belum lengkap' : ($item->margin_percentage ?? '—')])->all();
        $metadata[] = 'Margin = laba kotor ÷ omzet × 100%, sebelum biaya operasional.';
        $metadata[] = 'Urutan: '.$sortLabel;
        return compact('title', 'filePrefix', 'sheetTitle', 'headings', 'rows', 'currencyColumns', 'decimalColumns', 'metadata', 'from', 'to');
    }

    public function pdf(Request $request)
    {
        $tab = $this->exportTab($request);
        if ($tab !== 'transactions') {
            $data = $this->exportMarginsData($request);
            return Pdf::loadView('report.pdf.sale-margins', $data)->setPaper('a4', 'landscape')
                ->stream($data['filePrefix'].'-'.$data['from'].'-sd-'.$data['to'].'.pdf');
        }
        $categoryId = $this->categoryId($request);
        $cashier = $this->cashier($request);
        $from  = $request->get('from', now()->startOfMonth()->toDateString());
        $to    = $request->get('to', now()->toDateString());
        $sales = $this->periodSales($from, $to, $categoryId, $cashier?->id);
        $category = $categoryId ? Category::find($categoryId) : null;

        $pdf = Pdf::loadView('report.pdf.sale', compact('sales', 'from', 'to', 'category', 'cashier'))
                  ->setPaper('a4', 'portrait');
        return $pdf->stream("Laporan-Penjualan-{$from}-sd-{$to}.pdf");
    }

    public function excel(Request $request)
    {
        $tab = $this->exportTab($request);
        if ($tab !== 'transactions') {
            $data = $this->exportMarginsData($request);
            return Excel::download(new ReportExport($data['title'], $data['headings'], $data['rows'], $data['currencyColumns'],
                $data['decimalColumns'], $data['sheetTitle'], $data['metadata'], showGridlines: true,
                reportPeriod: 'Periode: '.$data['from'].' s.d. '.$data['to'], includeTotal: false),
                $data['filePrefix'].'-'.$data['from'].'-sd-'.$data['to'].'.xlsx');
        }
        $categoryId = $this->categoryId($request);
        $cashier = $this->cashier($request);
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to = $request->get('to', now()->toDateString());
        $sales = $this->periodSales($from, $to, $categoryId, $cashier?->id);
        $category = $categoryId ? Category::find($categoryId) : null;
        $rows = $sales->map(fn ($sale) => [
            $sale->sale_number, $sale->sale_date->format('d/m/Y'), $sale->customer_name ?? 'Umum',
            ucfirst($sale->payment_method), $sale->createdBy->name ?? '-', (float) $sale->report_total_amount,
        ])->all();

        $metadata = $category ? ['Kategori: '.$category->name, 'Nominal hanya mencakup barang dalam kategori terpilih.'] : [];
        if ($cashier) $metadata[] = 'Kasir: '.$cashier->name;

        return Excel::download(
            new ReportExport('Laporan Penjualan', ['No. Invoice', 'Tanggal', 'Pelanggan', 'Pembayaran', 'Kasir', $category ? 'Total Kategori' : 'Total'], $rows, [6], [], 'Penjualan', $metadata, showGridlines: true, reportPeriod: "Periode: {$from} s.d. {$to}"),
            "Laporan-Penjualan-{$from}-sd-{$to}.xlsx"
        );
    }
}
