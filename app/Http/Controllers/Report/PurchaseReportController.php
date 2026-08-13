<?php
namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\ReportExport;
use Maatwebsite\Excel\Facades\Excel;

class PurchaseReportController extends Controller
{
    public function index(Request $request)
    {
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to   = $request->get('to', now()->toDateString());

        $orders = PurchaseOrder::with(['supplier', 'items.product', 'createdBy'])
            ->whereBetween('order_date', [$from, $to])
            ->latest('order_date')
            ->get();

        $suppliers  = Supplier::orderBy('name')->get();
        $totalValue = $orders->sum(fn($po) => $po->totalValue());
        $completed  = $orders->where('status', 'completed')->count();
        $pending    = $orders->whereIn('status', ['pending', 'partial'])->count();

        return view('report.purchase', compact(
            'orders', 'from', 'to', 'suppliers',
            'totalValue', 'completed', 'pending'
        ));
    }

    public function pdf(Request $request)
    {
        $from   = $request->get('from', now()->startOfMonth()->toDateString());
        $to     = $request->get('to', now()->toDateString());
        $orders = PurchaseOrder::with(['supplier', 'items.product'])
            ->whereBetween('order_date', [$from, $to])
            ->latest()->get();

        $pdf = Pdf::loadView('report.pdf.purchase', compact('orders', 'from', 'to'))
                  ->setPaper('a4', 'portrait');
        return $pdf->stream("Laporan-Pembelian-{$from}-sd-{$to}.pdf");
    }

    public function excel(Request $request)
    {
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to = $request->get('to', now()->toDateString());
        $orders = PurchaseOrder::with(['supplier', 'items', 'createdBy'])
            ->whereBetween('order_date', [$from, $to])->latest('order_date')->get();
        $rows = $orders->map(fn ($order) => [
            $order->po_number, $order->order_date->format('d/m/Y'), $order->supplier->name,
            ucfirst($order->status), $order->items->count(), $order->createdBy->name ?? '-', (float) $order->totalValue(),
        ])->all();

        return Excel::download(
            new ReportExport("Laporan Pembelian {$from} s.d. {$to}", ['No. PO', 'Tanggal', 'Supplier', 'Status', 'Jumlah Item', 'Dibuat Oleh', 'Nilai PO'], $rows, [7], [], 'Pembelian'),
            "Laporan-Pembelian-{$from}-sd-{$to}.xlsx"
        );
    }
}
