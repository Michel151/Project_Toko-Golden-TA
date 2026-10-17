<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\ReportExport;
use Maatwebsite\Excel\Facades\Excel;

class SaleReportController extends Controller
{
    public function index(Request $request)
    {
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to   = $request->get('to', now()->toDateString());

        $sales = Sale::with(['createdBy', 'items.product'])
            ->paid()
            ->whereBetween('sale_date', [$from, $to])
            ->latest('sale_date')
            ->get();

        $totalAmount   = $sales->sum('total_amount');
        $totalTunai    = $sales->where('payment_method', 'tunai')->sum('total_amount');
        $totalTransfer = $sales->where('payment_method', 'transfer')->sum('total_amount');
        $totalTrx      = $sales->count();

        // Produk terlaris
        $topProducts = SaleItem::select('product_id', DB::raw('SUM(quantity) as total_qty'), DB::raw('SUM(subtotal) as total_amount'))
            ->whereHas('sale', fn($q) => $q->paid()->whereBetween('sale_date', [$from, $to]))
            ->with('product')
            ->groupBy('product_id')
            ->orderByDesc('total_amount')
            ->limit(10)
            ->get();

        return view('report.sale', compact(
            'sales', 'from', 'to',
            'totalAmount', 'totalTunai', 'totalTransfer', 'totalTrx',
            'topProducts'
        ));
    }

    public function pdf(Request $request)
    {
        $from  = $request->get('from', now()->startOfMonth()->toDateString());
        $to    = $request->get('to', now()->toDateString());
        $sales = Sale::with(['items.product'])
            ->paid()
            ->whereBetween('sale_date', [$from, $to])
            ->latest('sale_date')->get();

        $pdf = Pdf::loadView('report.pdf.sale', compact('sales', 'from', 'to'))
                  ->setPaper('a4', 'portrait');
        return $pdf->stream("Laporan-Penjualan-{$from}-sd-{$to}.pdf");
    }

    public function excel(Request $request)
    {
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to = $request->get('to', now()->toDateString());
        $sales = Sale::with('createdBy')->paid()->whereBetween('sale_date', [$from, $to])->latest('sale_date')->get();
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
