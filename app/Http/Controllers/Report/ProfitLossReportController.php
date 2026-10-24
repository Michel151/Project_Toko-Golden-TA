<?php

namespace App\Http\Controllers\Report;

use App\Exports\ProfitLossWorkbookExport;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Sale;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Support\FinancialPeriod;
use App\Services\InventoryReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ProfitLossReportController extends Controller
{
    private function data(Request $request): array
    {
        return DB::transaction(fn () => $this->buildData($request));
    }

    private function buildData(Request $request): array
    {
        $filters = FinancialPeriod::resolve($request);
        $costs = DB::table('sale_items')->select('sale_id')
            ->selectRaw("SUM(cost_subtotal) AS cost, COUNT(*) AS item_count, SUM(CASE WHEN cost_subtotal IS NULL OR cost_subtotal < 0 OR (cost_subtotal = 0 AND cost_method != 'moving_average') THEN 1 ELSE 0 END) AS missing_count")
            ->selectRaw("SUM(CASE WHEN cost_method = 'moving_average' THEN cost_subtotal ELSE 0 END) AS moving_cost,
                SUM(CASE WHEN cost_method != 'moving_average' THEN cost_subtotal ELSE 0 END) AS legacy_cost,
                SUM(CASE WHEN cost_method != 'moving_average' THEN 1 ELSE 0 END) AS legacy_count,
                SUM(CASE WHEN cost_method = 'moving_average' AND (cost_subtotal IS NULL OR cost_subtotal < 0) THEN 1 ELSE 0 END) AS moving_missing_count")
            ->groupBy('sale_id');
        $summary = Sale::paid()->leftJoinSub($costs, 'item_costs', 'item_costs.sale_id', '=', 'sales.id')
            ->whereDate('sale_date', '>=', $filters['from'])->whereDate('sale_date', '<=', $filters['to'])
            ->toBase()->selectRaw('COUNT(*) AS transaction_count, COALESCE(SUM(total_amount),0) AS revenue, COALESCE(SUM(item_costs.cost),0) AS cost,
                COALESCE(SUM(item_costs.moving_cost),0) AS moving_cost, COALESCE(SUM(item_costs.legacy_cost),0) AS legacy_cost,
                COALESCE(SUM(item_costs.legacy_count),0) AS legacy_count, COALESCE(SUM(item_costs.moving_missing_count),0) AS moving_missing_count,
                COALESCE(SUM(CASE WHEN COALESCE(item_costs.item_count,0) = 0 OR COALESCE(item_costs.missing_count,0) > 0 THEN 1 ELSE 0 END),0) AS incomplete_count')->first();
        $revenue = (float) $summary->revenue;
        $cost = (float) $summary->cost;
        $incompleteCount = (int) $summary->incomplete_count;
        $grossProfit = $incompleteCount ? null : round($revenue - $cost, 2);
        $query = Expense::where('status', 'confirmed')->whereDate('recognized_date', '>=', $filters['from'])->whereDate('recognized_date', '<=', $filters['to']);
        $grouped = (clone $query)->select('category')->selectRaw('SUM(amount) AS total')->groupBy('category')->pluck('total', 'category');
        $expenseTotal = round((float) $grouped->sum(), 2);
        $adjustments = DB::table('inventory_cost_entries')->whereBetween('event_date', [$filters['from'], $filters['to']])
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'stock_gain' THEN value_change ELSE 0 END),0) AS gains,
                COALESCE(SUM(CASE WHEN kind = 'stock_loss' THEN value_change ELSE 0 END),0) AS losses")->first();
        $stockGain = (float) $adjustments->gains;
        $stockLoss = (float) $adjustments->losses;
        $stockAdjustment = round($stockGain + $stockLoss, 2);
        $operatingProfit = $grossProfit === null ? null : round($grossProfit - $expenseTotal + $stockAdjustment, 2);
        $taxSummary = app(\App\Services\TaxSummaryService::class)->build($filters['from'], $filters['to']);
        $netProfit = $operatingProfit === null || !$taxSummary['complete'] ? null
            : (float) (string) \Brick\Math\BigDecimal::of((string) $operatingProfit)->minus($taxSummary['amount'])->toScale(2, \Brick\Math\RoundingMode::HalfUp);
        $lines = [
            ['PENDAPATAN', null, true, 'section'],
            ['Penjualan Barang Dagang', $revenue, false, 'detail'],
            ['Total Pendapatan', $revenue, true, 'total'],
            ['Harga Pokok Penjualan', $incompleteCount ? null : -$cost, true, 'total'],
        ];
        $lines[] = ['Laba Kotor', $grossProfit, true, 'result'];
        $lines[] = ['BEBAN OPERASIONAL', null, true, 'section'];
        foreach (Expense::CATEGORIES as $key => $label) {
            if ($grouped->has($key)) $lines[] = [str_starts_with($label, 'Beban ') ? $label : 'Beban '.$label, -(float) $grouped[$key], false, 'detail'];
        }
        if ($grouped->isEmpty()) $lines[] = ['Tidak ada beban terkonfirmasi', 0.0, false, 'detail'];
        $lines[] = ['Total Beban Operasional', -$expenseTotal, true, 'total'];
        $lines[] = ['Laba (Rugi) Usaha', $grossProfit === null ? null : round($grossProfit - $expenseTotal, 2), true, 'result'];
        $lines[] = ['PENDAPATAN DAN BEBAN LAINNYA', null, true, 'section'];
        $lines[] = ['Pendapatan Selisih Lebih Persediaan', $stockGain, false, 'detail'];
        $lines[] = ['Beban Selisih Kurang Persediaan', $stockLoss, false, 'detail'];
        $lines[] = ['Pendapatan dan Beban Lainnya Neto', $stockAdjustment, true, 'total'];
        $lines[] = ['Laba (Rugi) Sebelum Pajak', $operatingProfit, true, 'result'];
        $lines[] = ['Beban Pajak Penghasilan', $taxSummary['complete'] ? -(float) $taxSummary['amount'] : null, false, 'detail'];
        $lines[] = ['Laba (Rugi) Bersih', $netProfit, true, 'result'];
        $notice = 'Penjualan dan HPP: invoice lunas sesuai tanggal transaksi. HPP historis tetap; moving average mengikuti biaya tersimpan. Beban operasional sesuai tanggal pengakuan. Selisih opname tercatat pada pendapatan/beban lainnya dan perlu diverifikasi owner. Beban pajak diinput manual owner, bukan nominal pembayaran; bukan perhitungan pajak otomatis atau pajak tangguhan. '.$taxSummary['status'];
        $reconciliation = app(InventoryReconciliationService::class)->build($filters['from'], $filters['to'],
            (string) $summary->moving_cost, (string) $summary->legacy_cost, (int) $summary->moving_missing_count);
        $draftCount = Expense::where('status', 'draft')->whereDate('recognized_date', '>=', $filters['from'])->whereDate('recognized_date', '<=', $filters['to'])->count();
        return $filters + compact('revenue', 'cost', 'incompleteCount', 'grossProfit', 'expenseTotal', 'operatingProfit', 'netProfit', 'taxSummary', 'lines', 'notice', 'draftCount', 'reconciliation');
    }

    public function index(Request $request)
    {
        $data = $this->data($request);
        $expenses = Expense::where('status', 'confirmed')->whereDate('recognized_date', '>=', $data['from'])->whereDate('recognized_date', '<=', $data['to'])
            ->latest('recognized_date')->latest('id')->paginate(25)->withQueryString();
        return view('report.profit-loss', $data + compact('expenses'));
    }

    public function pdf(Request $request)
    {
        $data = $this->data($request);
        return Pdf::loadView('report.pdf.profit-loss', $data)->setPaper('a4')
            ->stream('Laporan-Laba-Rugi-'.$data['from'].'-sd-'.$data['to'].'.pdf');
    }

    public function excel(Request $request)
    {
        $data = $this->data($request);
        return Excel::download(new ProfitLossWorkbookExport($data), 'Laporan-Laba-Rugi-'.$data['from'].'-sd-'.$data['to'].'.xlsx');
    }
}
