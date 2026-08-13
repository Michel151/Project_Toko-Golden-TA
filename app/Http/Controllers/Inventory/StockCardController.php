<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

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

    private function cardData(Request $request, Product $product, bool $paginate = true): array
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'location_id' => 'nullable|exists:locations,id',
        ]);
        $product->load(['buyUnit', 'retailUnit', 'category', 'stocks']);
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
            ? $query->paginate(30)->withQueryString()
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
