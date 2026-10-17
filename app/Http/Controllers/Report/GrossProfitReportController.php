<?php

namespace App\Http\Controllers\Report;

use App\Exports\GrossProfitExport;
use App\Http\Controllers\Controller;
use App\Models\Sale;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class GrossProfitReportController extends Controller
{
    private function reportData(Request $request, bool $paginate = false): array
    {
        $today = now('Asia/Jakarta');
        $request->merge([
            'from' => $request->input('from') ?: $today->copy()->startOfMonth()->toDateString(),
            'to' => $request->input('to') ?: $today->toDateString(),
        ]);
        $filters = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $itemCosts = DB::table('sale_items')->select('sale_id')
            ->selectRaw('SUM(cost_subtotal) as cost, COUNT(*) as item_count, SUM(CASE WHEN cost_subtotal IS NULL OR cost_subtotal <= 0 THEN 1 ELSE 0 END) as missing_count')
            ->groupBy('sale_id');
        $query = Sale::paid()->leftJoinSub($itemCosts, 'item_costs', 'item_costs.sale_id', '=', 'sales.id')
            ->whereDate('sale_date', '>=', $filters['from'])->whereDate('sale_date', '<=', $filters['to']);
        $summary = (clone $query)->toBase()->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(total_amount),0) as revenue, COALESCE(SUM(item_costs.cost),0) as cost, COALESCE(SUM(CASE WHEN COALESCE(item_costs.item_count,0) = 0 OR COALESCE(item_costs.missing_count,0) > 0 THEN 1 ELSE 0 END),0) as incomplete_count')->first();
        $transactionCount = (int) $summary->transaction_count;
        $totalRevenue = (float) $summary->revenue;
        $totalCost = (float) $summary->cost;
        $incompleteCount = (int) $summary->incomplete_count;
        $grossProfit = $incompleteCount ? null : $totalRevenue - $totalCost;
        $grossMargin = $grossProfit !== null && $totalRevenue > 0 ? $grossProfit / $totalRevenue * 100 : null;

        $rows = (clone $query)->select('sales.*')->selectRaw('COALESCE(item_costs.cost,0) as report_cost, CASE WHEN COALESCE(item_costs.item_count,0) = 0 OR COALESCE(item_costs.missing_count,0) > 0 THEN 1 ELSE 0 END as incomplete_cost')
            ->with('createdBy')->orderByDesc('sale_date')->orderByDesc('sale_time')->orderByDesc('sales.id');
        $sales = $paginate ? $rows->paginate(25)->withQueryString() : $rows->get();
        return array_merge($filters, compact('sales', 'transactionCount', 'totalRevenue', 'totalCost', 'incompleteCount', 'grossProfit', 'grossMargin'));
    }

    public function index(Request $request)
    {
        return view('report.gross-profit', $this->reportData($request, true));
    }

    public function pdf(Request $request)
    {
        $data = $this->reportData($request);
        return Pdf::loadView('report.pdf.gross-profit', $data)->setPaper('a4', 'landscape')
            ->stream("Laporan-Laba-Kotor-{$data['from']}-sd-{$data['to']}.pdf");
    }

    public function excel(Request $request)
    {
        $data = $this->reportData($request);
        $rows = $data['sales']->map(function ($sale) {
            $revenue = (float) $sale->total_amount;
            $profit = $sale->incomplete_cost ? null : $revenue - (float) $sale->report_cost;
            return [$sale->sale_number, $sale->sale_date->format('d/m/Y'), $sale->transaction_time_label,
                $sale->customer_name ?: 'Umum', $sale->createdBy?->name ?? '-', $revenue,
                (float) $sale->report_cost, $profit, $profit !== null && $revenue > 0 ? number_format($profit / $revenue * 100, 2, ',', '.').'%' : '-',
                $sale->incomplete_cost ? 'HPP belum lengkap' : 'Lengkap'];
        })->all();
        $rows[] = ['TOTAL', '', '', '', '', $data['totalRevenue'], $data['totalCost'], $data['grossProfit'],
            $data['grossMargin'] !== null ? number_format($data['grossMargin'],2,',','.').'%' : '-',
            $data['incompleteCount'] ? $data['incompleteCount'].' nota HPP belum lengkap' : 'Lengkap'];
        return Excel::download(new GrossProfitExport(
            "Laba Kotor {$data['from']} s.d. {$data['to']} - Nota lunas; belum dikurangi biaya operasional",
            ['Invoice', 'Tanggal', 'Waktu', 'Pelanggan', 'Kasir', 'Penjualan', 'HPP Tercatat', 'Laba Kotor', 'Margin', 'Kelengkapan HPP'],
            $rows, [6,7,8], [], 'Laba Kotor'), "Laporan-Laba-Kotor-{$data['from']}-sd-{$data['to']}.xlsx");
    }
}
