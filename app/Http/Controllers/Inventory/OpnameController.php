<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OpnameController extends Controller
{
    public function __construct(protected StockService $stockService) {}

    public function index()
    {
        $opnames = StockOpname::with(['location', 'createdBy'])
                              ->latest()->paginate(15);
        return view('inventory.opnames.index', compact('opnames'));
    }

    public function create()
    {
        $locations = Location::orderBy('name')->get();
        return view('inventory.opnames.create', compact('locations'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'location_id'  => 'required|exists:locations,id',
            'opname_date'  => 'required|date',
        ]);

        // Buat draft opname dengan semua produk di lokasi tersebut
        $opname = DB::transaction(function () use ($request) {
            $opname = StockOpname::create([
                'opname_number' => StockOpname::generateOpnameNumber(),
                'location_id'   => $request->location_id,
                'opname_date'   => $request->opname_date,
                'status'        => 'draft',
                'created_by'    => auth()->id(),
            ]);

            // Ambil semua produk yang punya stok di lokasi ini
            $stocks = Stock::where('location_id', $request->location_id)
                           ->with('product')
                           ->get();

            foreach ($stocks as $stock) {
                StockOpnameItem::create([
                    'stock_opname_id'   => $opname->id,
                    'product_id'        => $stock->product_id,
                    'system_quantity'   => $stock->quantity,
                    'physical_quantity' => $stock->quantity, // default sama, nanti diisi
                    'difference'        => 0,
                ]);
            }

            return $opname;
        });

        return redirect()->route('inventory.opnames.edit', $opname)
                         ->with('success', 'Draft stock opname dibuat. Isi jumlah fisik setiap barang.');
    }

    public function edit(StockOpname $opname)
    {
        if ($opname->status === 'completed') {
            return redirect()->route('inventory.opnames.show', $opname)
                             ->with('error', 'Opname sudah selesai, tidak bisa diedit.');
        }

        $opname->load(['location', 'items.product.buyUnit', 'items.product.retailUnit']);
        return view('inventory.opnames.edit', compact('opname'));
    }

    public function update(Request $request, StockOpname $opname)
    {
        if ($opname->status === 'completed') {
            return back()->with('error', 'Opname sudah selesai.');
        }

        $request->validate([
            'items'                    => 'required|array',
            'items.*.physical_quantity'=> 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($request, $opname) {
            foreach ($request->items as $itemId => $data) {
                $item = StockOpnameItem::find($itemId);
                if (!$item) continue;

                $item->update([
                    'physical_quantity' => $data['physical_quantity'],
                    'difference'        => $data['physical_quantity'] - $item->system_quantity,
                ]);
            }
        });

        return back()->with('success', 'Data opname berhasil disimpan.');
    }

    public function show(StockOpname $opname)
    {
        $opname->load(['location', 'createdBy', 'items.product.buyUnit']);
        return view('inventory.opnames.show', compact('opname'));
    }

    // Selesaikan opname — update stok sesuai fisik
    public function complete(StockOpname $opname)
    {
        if ($opname->status === 'completed') {
            return back()->with('error', 'Opname sudah selesai.');
        }

        DB::transaction(function () use ($opname) {
            $opname->load(['items.product', 'location']);

            foreach ($opname->items as $item) {
                // Update stok sesuai hasil fisik
                $this->stockService->adjustStock(
                    $item->product,
                    $opname->location,
                    $item->physical_quantity,
                    $opname,
                    $opname->opname_date,
                    $opname->opname_number
                );

                // Simpan selisih final
                $item->update([
                    'difference' => $item->physical_quantity - $item->system_quantity,
                ]);
            }

            $opname->update(['status' => 'completed']);
        });

        return redirect()->route('inventory.opnames.show', $opname)
                         ->with('success', 'Stock opname selesai. Stok sistem telah disesuaikan.');
    }

    public function destroy(StockOpname $opname)
    {
        if ($opname->status === 'completed') {
            return back()->with('error', 'Opname selesai tidak bisa dihapus.');
        }
        $opname->delete();
        return redirect()->route('inventory.opnames.index')
                         ->with('success', 'Draft opname berhasil dihapus.');
    }
}
