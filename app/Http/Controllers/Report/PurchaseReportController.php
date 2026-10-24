<?php
namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\ReportExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\DB;

class PurchaseReportController extends Controller
{
    private function purchaseQuery(Request $request, string $from, string $to): Builder
    {
        $filters = $request->validate(['supplier_id'=>'nullable|integer|exists:suppliers,id']);

        return PurchaseOrder::whereDate('order_date', '>=', $from)->whereDate('order_date', '<=', $to)
            ->when($filters['supplier_id'] ?? null, fn ($query, $id) => $query->where('supplier_id', $id));
    }

    public function index(Request $request)
    {
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to   = $request->get('to', now()->toDateString());

        $filters = $request->validate(['from'=>'nullable|date', 'to'=>'nullable|date|after_or_equal:from', 'per_page'=>'nullable|integer|in:25,50,100']);
        $query = $this->purchaseQuery($request, $from, $to);
        $orderCount = (clone $query)->count();
        $completed = (clone $query)->where('status', 'completed')->count();
        $pending = (clone $query)->whereIn('status', ['pending', 'partial'])->count();
        $totalValue = (float) DB::table('purchase_order_items')->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->whereIn('purchase_orders.id', (clone $query)->select('purchase_orders.id'))->whereNull('purchase_orders.root_purchase_order_id')
            ->sum(DB::raw('quantity_ordered * buy_price'));
        $orders = $query->with(['supplier', 'items', 'createdBy'])->latest('order_date')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        $suppliers = Supplier::orderBy('name')->orderBy('id')->get(['id', 'name']);

        return view('report.purchase', compact(
            'orders', 'from', 'to', 'orderCount', 'totalValue', 'completed', 'pending', 'suppliers'
        ));
    }

    public function pdf(Request $request)
    {
        $from   = $request->get('from', now()->startOfMonth()->toDateString());
        $to     = $request->get('to', now()->toDateString());
        $orders = $this->purchaseQuery($request, $from, $to)->with(['supplier', 'items.product'])
            ->latest('order_date')->orderByDesc('id')->get();
        $supplierName = $request->filled('supplier_id') ? Supplier::whereKey($request->supplier_id)->value('name') : null;

        $pdf = Pdf::loadView('report.pdf.purchase', compact('orders', 'from', 'to', 'supplierName'))
                  ->setPaper('a4', 'portrait');
        return $pdf->stream("Laporan-Pembelian-{$from}-sd-{$to}.pdf");
    }

    public function excel(Request $request)
    {
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to = $request->get('to', now()->toDateString());
        $orders = $this->purchaseQuery($request, $from, $to)->with(['supplier', 'items', 'createdBy'])
            ->latest('order_date')->orderByDesc('id')->get();
        $supplierName = $request->filled('supplier_id') ? Supplier::whereKey($request->supplier_id)->value('name') : null;
        $rows = $orders->map(fn ($order) => [
            $order->po_number, $order->order_date->format('d/m/Y'), $order->supplier->name,
            $order->status_label, $order->items->count(), $order->createdBy->name ?? '-', (float) $order->totalValue(),
        ])->all();

        return Excel::download(
            new ReportExport("Laporan Pembelian {$from} s.d. {$to}", ['No. PO', 'Tanggal', 'Supplier', 'Status', 'Jumlah Item', 'Dibuat Oleh', 'Nilai PO'], $rows, [7], [], 'Pembelian', $supplierName ? ['Supplier: '.$supplierName] : []),
            "Laporan-Pembelian-{$from}-sd-{$to}.xlsx"
        );
    }
}
