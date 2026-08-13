<?php

namespace App\Http\Controllers\Report;

use App\Exports\ReportExport;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\GrossProfitReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class GrossProfitReportController extends Controller
{
    public function __construct(private readonly GrossProfitReportService $report) {}

    public function index(Request $request)
    {
        [$from, $to, $categoryId] = $this->filters($request);
        $data = $this->report->generate($from, $to, $categoryId);
        $categories = Category::orderBy('name')->get(['id', 'name']);

        return view('report.gross-profit', compact('from', 'to', 'categoryId', 'categories') + $data);
    }

    public function pdf(Request $request)
    {
        [$from, $to, $categoryId] = $this->filters($request);
        $data = $this->report->generate($from, $to, $categoryId);
        $category = $categoryId ? Category::find($categoryId) : null;

        return Pdf::loadView('report.pdf.gross-profit', compact('from', 'to', 'category') + $data)
            ->setPaper('a4', 'landscape')
            ->stream("Laporan-Laba-Kotor-{$from}-sd-{$to}.pdf");
    }

    public function excel(Request $request)
    {
        [$from, $to, $categoryId] = $this->filters($request);
        $data = $this->report->generate($from, $to, $categoryId);
        $rows = $data['products']->map(fn ($product) => [
            $product->product_name,
            $product->category_name,
            (float) $product->wholesale_qty,
            (float) $product->retail_qty,
            $product->transaction_count,
            $product->revenue,
            $product->cost,
            $product->gross_profit,
            $product->margin,
        ])->all();

        return Excel::download(
            new ReportExport(
                "Laporan Laba Kotor {$from} s.d. {$to}",
                ['Barang', 'Kategori', 'Qty Grosir', 'Qty Ecer', 'Transaksi', 'Omzet', 'HPP', 'Laba Kotor', 'Margin (%)'],
                $rows,
                [6, 7, 8],
                [3, 4, 9],
                'Laba Kotor'
            ),
            "Laporan-Laba-Kotor-{$from}-sd-{$to}.xlsx"
        );
    }

    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
        ]);

        return [
            $validated['from'] ?? now()->startOfMonth()->toDateString(),
            $validated['to'] ?? now()->toDateString(),
            isset($validated['category_id']) ? (int) $validated['category_id'] : null,
        ];
    }
}
