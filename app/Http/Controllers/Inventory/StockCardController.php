<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use App\Exports\ReportExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class StockCardController extends Controller
{
    public function show(Request $request, Product $product)
    {
        $data = $this->cardData($request, $product);
        return view('inventory.stock-card.show', $data);
    }

    public function pdf(Request $request, Product $product)
    {
        $data = $this->cardData($request, $product, false);
        $pdf = Pdf::loadView('inventory.stock-card.pdf', $data)->setPaper('a4', 'landscape');
        return $pdf->stream('Kartu-Stok-' . str($product->name)->slug() . '-' . date('Y-m-d') . '.pdf');
    }

    public function excel(Request $request, Product $product)
    {
        $data = $this->cardData($request, $product, false);
        $rows = $data['movements']->map(function ($movement) use ($product) {
            $quantity = (float) $movement->quantity;
            return [$movement->movement_date->format('d/m/Y'), $movement->reference_number ?? '-',
                $movement->type_label.($movement->notes ? ' - '.$movement->notes : ''), $movement->location->name, $movement->createdBy->name ?? 'Sistem',
                $quantity > 0 ? $product->displayStock($quantity) : '-',
                $quantity < 0 ? $product->displayStock(abs($quantity)) : '-',
                $product->displayStock((float) $movement->card_balance)];
        })->all();
        if (! $rows) $rows[] = ['Tidak ada mutasi sesuai filter.', null, null, null, null, null, null, null];
        $rows[] = ['TOTAL', null, 'Saldo Akhir', null, null, $product->displayStock($data['totalIn']),
            $product->displayStock($data['totalOut']), $product->displayStock($data['closingBalance'])];
        $location = $data['locationId'] ? $data['locations']->firstWhere('id', $data['locationId'])?->name : 'Semua Lokasi';
        $metadata = ['Barang: '.$product->name, 'Kategori: '.($product->category?->name ?? '-'), 'Lokasi: '.$location,
            'Saldo Awal: '.$product->displayStock($data['openingBalance']), 'Barang Masuk: '.$product->displayStock($data['totalIn']),
            'Barang Keluar: '.$product->displayStock($data['totalOut']), 'Saldo Akhir: '.$product->displayStock($data['closingBalance'])];
        return Excel::download(new ReportExport('Kartu Stok Barang',
            ['Tanggal','Referensi','Keterangan','Lokasi','Petugas','Masuk','Keluar','Saldo'], $rows, [], [6,7,8], 'Kartu Stok', $metadata,
            showGridlines: true, reportPeriod: 'Periode: '.$data['from'].' s.d. '.$data['to'], includeTotal: false),
            'Kartu-Stok-'.str($product->name)->slug().'-'.$data['from'].'-sd-'.$data['to'].'.xlsx');
    }

    private function cardData(Request $request, Product $product, bool $paginate = true): array
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'location_id' => 'nullable|exists:locations,id',
        ]);
        $product->load([
            'buyUnit',
            'retailUnit',
            'category',
            'stocks',
            'productLocations.location',
            'productUnits.unit',
            'stockBatches.location',
            'stockBatches.purchaseReceiptItem.purchaseReceipt.purchaseOrder',
        ]);
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to = $request->get('to', now()->toDateString());
        $locationId = $request->filled('location_id') ? (int) $request->location_id : null;

        $query = StockMovement::with(['location', 'createdBy'])
            ->where('product_id', $product->id)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->whereBetween('movement_date', [$from, $to])
            ->orderBy('movement_date')->orderBy('id');

        $periodMovements = (clone $query)->get();
        $currentBalance = $locationId
            ? (float) ($product->stocks->firstWhere('location_id', $locationId)?->quantity ?? 0)
            : (float) $product->stocks->sum('quantity');
        $movementsAfterPeriod = (float) StockMovement::where('product_id', $product->id)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->whereDate('movement_date', '>', $to)->sum('quantity');
        $closingBalance = $currentBalance - $movementsAfterPeriod;
        $netMovement = (float) $periodMovements->sum('quantity');
        $openingBalance = $closingBalance - $netMovement;
        $totalIn = (float) $periodMovements->where('quantity', '>', 0)->sum('quantity');
        $totalOut = abs((float) $periodMovements->where('quantity', '<', 0)->sum('quantity'));

        $runningBalance = $openingBalance;
        $cardRows = $periodMovements->map(function ($movement) use (&$runningBalance) {
            $runningBalance += (float) $movement->quantity;
            $movement->card_balance = $runningBalance;
            return $movement;
        });

        $movements = $paginate
            ? $query->reorder()->orderByDesc('movement_date')->orderByDesc('id')->paginate(25)->withQueryString()
            : $cardRows;
        if ($paginate) {
            $rowsById = $cardRows->keyBy('id');
            $movements->getCollection()->each(function ($movement) use ($rowsById) {
                $movement->card_balance = $rowsById[$movement->id]->card_balance;
            });
        }

        return compact(
            'product', 'movements', 'from', 'to', 'locationId', 'openingBalance',
            'closingBalance', 'totalIn', 'totalOut'
        ) + ['locations' => Location::orderBy('name')->get()];
    }
}
