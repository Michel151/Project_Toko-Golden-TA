<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\ReportExport;
use Maatwebsite\Excel\Facades\Excel;

class SaleReportController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['per_page'=>'nullable|integer|in:25,50,100', 'tab'=>'nullable|in:transactions,products']);
        $activeTab = $filters['tab'] ?? 'transactions';
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to   = $request->get('to', now()->toDateString());

        $sales = Sale::with(['createdBy', 'items.product'])
            ->paid()
            ->whereDate('sale_date', '>=', $from)->whereDate('sale_date', '<=', $to)
            ->latest('sale_date')
            ->latest('created_at')->latest('id')
            ->get();

        $totalAmount   = $sales->sum('total_amount');
        $totalTunai    = $sales->where('payment_method', 'tunai')->sum('total_amount');
        $totalTransfer = $sales->where('payment_method', 'transfer')->sum('total_amount');
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
            })->sortByDesc('revenue')->values();

        // Produk terlaris
        $topProducts = SaleItem::select('product_id', DB::raw('SUM(quantity) as total_qty'), DB::raw('SUM(subtotal) as total_amount'))
            ->whereHas('sale', fn($q) => $q->paid()->whereDate('sale_date', '>=', $from)->whereDate('sale_date', '<=', $to))
            ->with('product')
            ->groupBy('product_id')
            ->orderByDesc('total_amount')
            ->limit(10)
            ->get();

        $perPage = (int) ($filters['per_page'] ?? 25);
        $page = LengthAwarePaginator::resolveCurrentPage();
        $sales = (new LengthAwarePaginator(
            $sales->forPage($page, $perPage)->values(), $sales->count(), $perPage, $page,
            ['path'=>LengthAwarePaginator::resolveCurrentPath()]
        ))->withQueryString();

        return view('report.sale', compact(
            'sales', 'from', 'to',
            'totalAmount', 'totalTunai', 'totalTransfer', 'totalTrx',
            'grossProfit', 'incompleteCostCount', 'productMargins',
            'topProducts', 'activeTab'
        ));
    }

    public function pdf(Request $request)
    {
        $from  = $request->get('from', now()->startOfMonth()->toDateString());
        $to    = $request->get('to', now()->toDateString());
        $sales = Sale::with(['items.product'])
            ->paid()
            ->whereDate('sale_date', '>=', $from)->whereDate('sale_date', '<=', $to)
            ->latest('sale_date')->latest('created_at')->latest('id')->get();

        $pdf = Pdf::loadView('report.pdf.sale', compact('sales', 'from', 'to'))
                  ->setPaper('a4', 'portrait');
        return $pdf->stream("Laporan-Penjualan-{$from}-sd-{$to}.pdf");
    }

    public function excel(Request $request)
    {
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to = $request->get('to', now()->toDateString());
        $sales = Sale::with('createdBy')->paid()->whereDate('sale_date', '>=', $from)->whereDate('sale_date', '<=', $to)->latest('sale_date')->latest('created_at')->latest('id')->get();
        $rows = $sales->map(fn ($sale) => [
            $sale->sale_number, $sale->sale_date->format('d/m/Y'), $sale->customer_name ?? 'Umum',
            ucfirst($sale->payment_method), $sale->createdBy->name ?? '-', (float) $sale->total_amount,
        ])->all();

        return Excel::download(
            new ReportExport("Laporan Penjualan {$from} s.d. {$to}", ['No. Invoice', 'Tanggal', 'Pelanggan', 'Pembayaran', 'Kasir', 'Total'], $rows, [6], [], 'Penjualan'),
            "Laporan-Penjualan-{$from}-sd-{$to}.xlsx"
        );
    }
}
