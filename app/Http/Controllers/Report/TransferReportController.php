<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Models\StockTransfer;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\ReportExport;
use Maatwebsite\Excel\Facades\Excel;

class TransferReportController extends Controller
{
    public function index(Request $request)
    {
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to   = $request->get('to', now()->toDateString());

        $transfers = StockTransfer::with(['product.buyUnit', 'product.retailUnit', 'fromLocation', 'toLocation', 'createdBy'])
            ->whereBetween('transfer_date', [$from, $to])
            ->latest('transfer_date')
            ->get();

        $locations = Location::orderBy('name')->get();
        $products  = Product::orderBy('name')->get();

        return view('report.transfer', compact('transfers', 'from', 'to', 'locations', 'products'));
    }

    public function pdf(Request $request)
    {
        $from      = $request->get('from', now()->startOfMonth()->toDateString());
        $to        = $request->get('to', now()->toDateString());
        $transfers = StockTransfer::with(['product.buyUnit', 'product.retailUnit', 'fromLocation', 'toLocation', 'createdBy'])
            ->whereBetween('transfer_date', [$from, $to])
            ->latest()->get();

        $pdf = Pdf::loadView('report.pdf.transfer', compact('transfers', 'from', 'to'))
                  ->setPaper('a4', 'portrait');
        return $pdf->stream("Laporan-Transfer-{$from}-sd-{$to}.pdf");
    }

    public function excel(Request $request)
    {
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to = $request->get('to', now()->toDateString());
        $transfers = StockTransfer::with(['product.buyUnit', 'product.retailUnit', 'fromLocation', 'toLocation', 'createdBy'])
            ->whereBetween('transfer_date', [$from, $to])->latest('transfer_date')->get();
        $rows = $transfers->map(fn ($transfer) => [
            $transfer->transfer_number, $transfer->transfer_date->format('d/m/Y'), $transfer->product->name,
            $transfer->fromLocation->name, $transfer->toLocation->name,
            (float) $transfer->quantity,
            $transfer->product->can_retail ? ($transfer->product->retailUnit->name ?? $transfer->product->buyUnit->name) : $transfer->product->buyUnit->name,
            $transfer->createdBy->name ?? '-', $transfer->notes ?? '-',
        ])->all();

        return Excel::download(
            new ReportExport("Laporan Transfer {$from} s.d. {$to}", ['No. Transfer', 'Tanggal', 'Barang', 'Dari', 'Ke', 'Jumlah', 'Satuan Stok', 'Petugas', 'Catatan'], $rows, [], [6], 'Transfer'),
            "Laporan-Transfer-{$from}-sd-{$to}.xlsx"
        );
    }
}
