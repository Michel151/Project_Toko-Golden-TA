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

        $transfers = StockTransfer::with(['items.product.buyUnit', 'items.product.retailUnit', 'items.product.productUnits.unit', 'fromLocation', 'toLocation', 'createdBy'])
            ->whereDate('transfer_date', '>=', $from)
            ->whereDate('transfer_date', '<=', $to)
            ->latest('transfer_date')
            ->get();

        $locations = Location::orderBy('name')->get();
        $products  = Product::orderBy('name')->get();
        $transferSummary = [
            'documents'=>$transfers->count(),
            'products'=>$transfers->flatMap(fn ($transfer) => $transfer->items->pluck('product_id'))->unique()->count(),
            'origins'=>$transfers->pluck('from_location_id')->unique()->count(),
            'destinations'=>$transfers->pluck('to_location_id')->unique()->count(),
        ];

        return view('report.transfer', compact('transfers', 'from', 'to', 'locations', 'products', 'transferSummary'));
    }

    public function pdf(Request $request)
    {
        $from      = $request->get('from', now()->startOfMonth()->toDateString());
        $to        = $request->get('to', now()->toDateString());
        $transfers = StockTransfer::with(['items.product.buyUnit', 'items.product.retailUnit', 'items.product.productUnits.unit', 'fromLocation', 'toLocation', 'createdBy'])
            ->whereDate('transfer_date', '>=', $from)
            ->whereDate('transfer_date', '<=', $to)
            ->latest()->get();

        $pdf = Pdf::loadView('report.pdf.transfer', compact('transfers', 'from', 'to'))
                  ->setPaper('a4', 'landscape');
        return $pdf->stream("Laporan-Transfer-{$from}-sd-{$to}.pdf");
    }

    public function excel(Request $request)
    {
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to = $request->get('to', now()->toDateString());
        $transfers = StockTransfer::with(['items.product.buyUnit', 'items.product.retailUnit', 'items.product.productUnits.unit', 'fromLocation', 'toLocation', 'createdBy'])
            ->whereDate('transfer_date', '>=', $from)
            ->whereDate('transfer_date', '<=', $to)->latest('transfer_date')->get();
        $rows = $transfers->flatMap(fn ($transfer) => $transfer->items->map(fn ($item) => [
            $transfer->transfer_number, $transfer->transfer_date->format('d/m/Y'), $item->product->name,
            $transfer->fromLocation->name, $transfer->toLocation->name,
            (float) $item->quantity,
            $item->product->baseProductUnit?->unit?->name ?? ($item->product->can_retail ? ($item->product->retailUnit->name ?? $item->product->buyUnit->name) : $item->product->buyUnit->name),
            $transfer->createdBy->name ?? '-', $transfer->notes ?? '-',
        ]))->all();

        return Excel::download(
            new ReportExport("Laporan Transfer {$from} s.d. {$to}", ['No. Transfer', 'Tanggal', 'Barang', 'Dari', 'Ke', 'Jumlah', 'Satuan Stok', 'Petugas', 'Catatan'], $rows, [], [6], 'Transfer'),
            "Laporan-Transfer-{$from}-sd-{$to}.xlsx"
        );
    }
}
