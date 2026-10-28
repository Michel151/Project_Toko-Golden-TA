<?php

namespace App\Http\Controllers\Report;

use App\Http\Controllers\Controller;
use App\Models\StockTransfer;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\ReportExport;
use Maatwebsite\Excel\Facades\Excel;

class TransferReportController extends Controller
{
    private function transferQuery(Request $request, string $from, string $to): Builder
    {
        $filters = $request->validate([
            'from_location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'to_location_id' => ['nullable', 'integer', 'exists:locations,id'],
        ]);

        return StockTransfer::with(['items.product.buyUnit', 'items.product.retailUnit', 'items.product.productUnits.unit', 'fromLocation', 'toLocation', 'createdBy'])
            ->whereDate('transfer_date', '>=', $from)
            ->whereDate('transfer_date', '<=', $to)
            ->when($filters['from_location_id'] ?? null, fn ($query, $id) => $query->where('from_location_id', $id))
            ->when($filters['to_location_id'] ?? null, fn ($query, $id) => $query->where('to_location_id', $id))
            ->latest('transfer_date')->latest('created_at')->latest('id');
    }

    public function index(Request $request)
    {
        $filters = $request->validate(['per_page'=>'nullable|integer|in:25,50,100']);
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to   = $request->get('to', now()->toDateString());

        $transfers = $this->transferQuery($request, $from, $to)
            ->get();

        $locations = Location::orderBy('name')->get();
        $products  = Product::orderBy('name')->get();
        $transferSummary = [
            'documents'=>$transfers->count(),
            'products'=>$transfers->flatMap(fn ($transfer) => $transfer->items->pluck('product_id'))->unique()->count(),
            'origins'=>$transfers->pluck('from_location_id')->unique()->count(),
            'destinations'=>$transfers->pluck('to_location_id')->unique()->count(),
        ];

        $perPage = (int) ($filters['per_page'] ?? 25);
        $page = LengthAwarePaginator::resolveCurrentPage();
        $transfers = (new LengthAwarePaginator(
            $transfers->forPage($page, $perPage)->values(), $transfers->count(), $perPage, $page,
            ['path'=>LengthAwarePaginator::resolveCurrentPath()]
        ))->withQueryString();

        return view('report.transfer', compact('transfers', 'from', 'to', 'locations', 'products', 'transferSummary'));
    }

    public function pdf(Request $request)
    {
        $from      = $request->get('from', now()->startOfMonth()->toDateString());
        $to        = $request->get('to', now()->toDateString());
        $transfers = $this->transferQuery($request, $from, $to)
            ->get();

        $pdf = Pdf::loadView('report.pdf.transfer', compact('transfers', 'from', 'to'))
                  ->setPaper('a4', 'landscape');
        return $pdf->stream("Laporan-Transfer-{$from}-sd-{$to}.pdf");
    }

    public function excel(Request $request)
    {
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to = $request->get('to', now()->toDateString());
        $transfers = $this->transferQuery($request, $from, $to)->get();
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
