<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransferController extends Controller
{
    public function __construct(protected StockService $stockService) {}

    public function index(Request $request)
    {
        $query = StockTransfer::with(['product.buyUnit', 'product.retailUnit', 'product.productUnits.unit', 'productUnit.unit', 'fromLocation', 'toLocation', 'createdBy']);

        if ($request->filled('from')) {
            $query->whereDate('transfer_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('transfer_date', '<=', $request->to);
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }

        $transfers = $query->latest()->paginate(20)->withQueryString();
        $products  = Product::orderBy('name')->get();

        return view('inventory.transfers.index', compact('transfers', 'products'));
    }

    public function create()
    {
        $locations  = Location::orderBy('name')->get();
        $products   = Product::with(['buyUnit', 'retailUnit', 'stocks.location', 'productUnits.unit'])
                            ->orderBy('name')->get();

        $productsJson = $products->map(function($p) {
            return [
                'id'                => $p->id,
                'name'              => $p->name,
                'can_retail'        => $p->can_retail,
                'conversion_factor' => (float) $p->conversion_factor,
                'unit_name_buy'     => $p->buyUnit->name,
                'unit_name_retail'  => optional($p->retailUnit)->name,
                'unit_name_stored'  => $p->can_retail
                    ? (optional($p->retailUnit)->name ?? $p->buyUnit->name)
                    : $p->buyUnit->name,
                'product_units' => $p->productUnits
                    ->sortByDesc('conversion_to_base')
                    ->map(fn ($productUnit) => [
                        'id' => $productUnit->id,
                        'name' => $productUnit->unit->name,
                        'conversion_to_base' => (float) $productUnit->conversion_to_base,
                        'is_base_unit' => $productUnit->is_base_unit,
                    ])
                    ->values()
                    ->all(),
                'stocks'    => $p->stocks->map(function($s) {
                    return [
                        'location_id' => $s->location_id,
                        'quantity'    => (float) $s->quantity,
                    ];
                })->values()->toArray(),
            ];
        })->values()->toArray();

        return view('inventory.transfers.form', compact('locations', 'products', 'productsJson'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'product_id'       => 'required|exists:products,id',
            'from_location_id' => 'required|exists:locations,id',
            'to_location_id'   => 'required|exists:locations,id|different:from_location_id',
            'quantity'         => 'required|numeric|min:0.01',
            'product_unit_id'  => 'required|exists:product_units,id',
            'transfer_date'    => 'required|date',
            'notes'            => 'nullable|string|max:500',
        ], [
            'to_location_id.different' => 'Lokasi tujuan harus berbeda dari lokasi asal.',
        ]);

        $unitMatchesProduct = DB::table('product_units')
            ->where('id', $request->product_unit_id)
            ->where('product_id', $request->product_id)
            ->exists();

        if (! $unitMatchesProduct) {
            return back()
                ->withErrors(['product_unit_id' => 'Satuan transfer tidak sesuai dengan barang yang dipilih.'])
                ->withInput();
        }

        DB::transaction(function () use ($request) {
            $product  = Product::with('productUnits')->findOrFail($request->product_id);
            $productUnit = $product->productUnits->firstWhere('id', (int) $request->product_unit_id);
            if (! $productUnit) {
                throw new \Exception('Satuan transfer tidak sesuai dengan produk.');
            }
            $from     = Location::findOrFail($request->from_location_id);
            $to       = Location::findOrFail($request->to_location_id);
            $stockQuantity = (float) $request->quantity * (float) $productUnit->conversion_to_base;

            $transfer = StockTransfer::create([
                'transfer_number'  => StockTransfer::generateTransferNumber(),
                'product_id'       => $product->id,
                'product_unit_id'  => $productUnit->id,
                'from_location_id' => $from->id,
                'to_location_id'   => $to->id,
                'quantity'         => $stockQuantity,
                'transfer_date'    => $request->transfer_date,
                'notes'            => $request->notes,
                'created_by'       => auth()->id(),
            ]);

            $this->stockService->transferStock(
                $product,
                $from,
                $to,
                $stockQuantity,
                $transfer,
                $transfer->transfer_date,
                $transfer->transfer_number
            );
        });

        return redirect()->route('inventory.transfers.index')
                         ->with('success', 'Transfer stok berhasil dicatat.');
    }

    public function show(StockTransfer $transfer)
    {
        $transfer->load(['product.buyUnit', 'product.retailUnit', 'product.productUnits.unit', 'productUnit.unit', 'fromLocation', 'toLocation', 'createdBy']);
        return view('inventory.transfers.show', compact('transfer'));
    }

    // Transfer tidak bisa diedit/dihapus
    public function edit(StockTransfer $transfer)
    {
        return back()->with('error', 'Transfer tidak bisa diedit.');
    }

    public function update(Request $request, StockTransfer $transfer)
    {
        return back()->with('error', 'Transfer tidak bisa diedit.');
    }

    public function destroy(StockTransfer $transfer)
    {
        return back()->with('error', 'Transfer tidak bisa dihapus untuk menjaga integritas stok.');
    }

    // AJAX: ambil stok produk di lokasi tertentu
    public function getStock(Request $request)
    {
        $stock = \App\Models\Stock::where('product_id', $request->product_id)
                                  ->where('location_id', $request->location_id)
                                  ->first();

        $product = Product::with(['buyUnit', 'retailUnit', 'productUnits.unit'])->find($request->product_id);

        return response()->json([
            'quantity'  => $stock ? (float) $stock->quantity : 0,
            'unit_name' => $product->baseProductUnit?->unit?->name
                ?? ($product->can_retail ? $product->retailUnit->name ?? $product->buyUnit->name : $product->buyUnit->name),
        ]);
    }
}
