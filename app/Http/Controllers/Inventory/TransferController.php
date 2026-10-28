<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransferController extends Controller
{
    public function __construct(protected StockService $stockService) {}

    public function index(Request $request)
    {
        ['from'=>$from, 'to'=>$to] = \App\Support\MonthlyDateRange::fromRequest($request);
        $filters = $request->validate([
            'from_location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'to_location_id' => ['nullable', 'integer', 'exists:locations,id'],
        ]);
        $query = StockTransfer::with(['items.product.buyUnit', 'items.product.retailUnit', 'items.product.productUnits.unit', 'items.productUnit.unit', 'fromLocation', 'toLocation', 'createdBy']);
        $query->when($filters['from_location_id'] ?? null, fn ($query, $id) => $query->where('from_location_id', $id))
            ->when($filters['to_location_id'] ?? null, fn ($query, $id) => $query->where('to_location_id', $id));

        if ($from) {
            $query->whereDate('transfer_date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('transfer_date', '<=', $to);
        }
        if ($request->filled('product_id')) {
            $query->whereHas('items', fn ($q) => $q->where('product_id', $request->product_id));
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('transfer_number', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('items.product', fn ($productQuery) => $productQuery->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('fromLocation', fn ($locationQuery) => $locationQuery->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('toLocation', fn ($locationQuery) => $locationQuery->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('createdBy', fn ($userQuery) => $userQuery->where('name', 'like', "%{$search}%"));
            });
        }

        $transfers = $query->latest()->paginate(20)->withQueryString()->appends(['from'=>$from ?? '', 'to'=>$to ?? '']);
        $products  = Product::orderBy('name')->get();
        $locations = Location::orderBy('name')->get();

        return view('inventory.transfers.index', compact('transfers', 'products', 'locations', 'from', 'to'));
    }

    public function create()
    {
        $locations  = Location::orderBy('name')->get();
        $products   = Product::with(['buyUnit', 'retailUnit', 'stocks.location', 'stockBatches', 'category', 'productUnits.unit'])
                            ->active()
                            ->orderBy('name')->get();

        $productsJson = $products->map(function($p) {
            return [
                'id'                => $p->id,
                'name'              => $p->name,
                'barcode'           => $p->barcode,
                'category_id'       => $p->category_id,
                'category_name'     => optional($p->category)->name ?? 'Tanpa Kategori',
                'can_retail'        => $p->can_retail,
                'is_expirable'      => (bool) $p->is_expirable,
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
                'stocks'    => $p->stocks->map(function($s) use ($p) {
                    $sellableQuantity = $p->is_expirable
                        ? $p->stockBatches
                            ->where('location_id', $s->location_id)
                            ->filter(fn ($batch) => (float) $batch->quantity_remaining > 0 && (! $batch->expired_date || $batch->expired_date->gte(today())))
                            ->sum(fn ($batch) => (float) $batch->quantity_remaining)
                        : (float) $s->quantity;

                    return [
                        'location_id' => $s->location_id,
                        'quantity'    => (float) $s->quantity,
                        'sellable_quantity' => (float) $sellableQuantity,
                    ];
                })->values()->toArray(),
            ];
        })->values()->toArray();

        return view('inventory.transfers.form', compact('locations', 'products', 'productsJson'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|integer|distinct|exists:products,id,is_active,1',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.product_unit_id' => 'required|integer|exists:product_units,id',
            'items.*.from_location_id' => 'prohibited',
            'items.*.to_location_id' => 'prohibited',
            'from_location_id' => 'required|exists:locations,id',
            'to_location_id'   => 'required|exists:locations,id|different:from_location_id',
            'transfer_date'    => 'required|date',
            'notes'            => 'nullable|string|max:500',
        ], [
            'to_location_id.different' => 'Lokasi tujuan harus berbeda dari lokasi asal.',
            'items.required' => 'Tambahkan minimal satu barang untuk ditransfer.',
            'items.*.product_id.distinct' => 'Barang yang sama hanya boleh ditambahkan satu kali.',
            'items.*.from_location_id.prohibited' => 'Semua barang harus memakai lokasi asal pada informasi transfer.',
            'items.*.to_location_id.prohibited' => 'Semua barang harus memakai lokasi tujuan pada informasi transfer.',
        ]);

        $products = Product::with('productUnits')->whereIn('id', array_column($data['items'], 'product_id'))->get()->keyBy('id');
        foreach ($data['items'] as $index => $item) {
            if (! $products[$item['product_id']]->productUnits->firstWhere('id', (int) $item['product_unit_id'])) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_unit_id" => 'Satuan transfer tidak sesuai dengan barang yang dipilih.',
                ]);
            }
        }

        DB::transaction(function () use ($data, $products) {
            $from = Location::findOrFail($data['from_location_id']);
            $to = Location::findOrFail($data['to_location_id']);
            $first = $data['items'][array_key_first($data['items'])];
            $firstUnit = $products[$first['product_id']]->productUnits->firstWhere('id', (int) $first['product_unit_id']);

            // Keep the legacy single-product columns for existing integrations.
            $transfer = StockTransfer::create([
                'transfer_number'  => StockTransfer::generateTransferNumber(),
                'product_id'       => $first['product_id'],
                'product_unit_id'  => $firstUnit->id,
                'from_location_id' => $from->id,
                'to_location_id'   => $to->id,
                'quantity'         => (float) $first['quantity'] * (float) $firstUnit->conversion_to_base,
                'transfer_date'    => $data['transfer_date'],
                'notes'            => $data['notes'] ?? null,
                'created_by'       => auth()->id(),
            ]);

            foreach ($data['items'] as $index => $item) {
                $product = $products[$item['product_id']];
                $unit = $product->productUnits->firstWhere('id', (int) $item['product_unit_id']);
                $quantity = (float) $item['quantity'] * (float) $unit->conversion_to_base;
                $transfer->items()->create([
                    'product_id' => $product->id,
                    'product_unit_id' => $unit->id,
                    'quantity' => $quantity,
                ]);
                try {
                    $this->stockService->transferStock($product, $from, $to, $quantity, $transfer, $transfer->transfer_date, $transfer->transfer_number);
                } catch (\Exception $exception) {
                    if ($exception instanceof \Illuminate\Database\QueryException) {
                        throw $exception;
                    }
                    throw ValidationException::withMessages(["items.{$index}.quantity" => $exception->getMessage()]);
                }
            }
        });

        return redirect()->route('inventory.transfers.index')
                         ->with('success', 'Transfer stok berhasil dicatat.');
    }

    public function availability(Request $request)
    {
        $data = $request->validate([
            'from_location_id' => 'required|integer|exists:locations,id',
            'product_ids' => 'required|array|min:1|max:100',
            'product_ids.*' => 'required|integer|distinct|exists:products,id,is_active,1',
        ]);
        $products = Product::with(['stocks', 'stockBatches'])->whereIn('id', $data['product_ids'])->get();
        return response()->json([
            'from_location_id' => (int) $data['from_location_id'],
            'products' => $products->map(function ($product) use ($data) {
                $availability = $product->stockAvailability((int) $data['from_location_id']);
                return ['id'=>$product->id, 'quantity'=>$availability['physical'], 'sellable_quantity'=>$availability['sellable']];
            })->values(),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, StockTransfer $transfer)
    {
        $filters = $request->validate(['q'=>'nullable|string|max:150', 'per_page'=>'nullable|integer|in:25,50,100']);
        $search = trim($filters['q'] ?? '');
        $transfer->load(['fromLocation', 'toLocation', 'createdBy'])->loadCount('items');
        $items = $transfer->items()->with(['product.buyUnit', 'product.retailUnit', 'product.productUnits.unit', 'productUnit.unit'])
            ->when($search !== '', fn ($query) => $query->whereHas('product', fn ($products) => $products->where('name', 'like', '%'.$search.'%')))
            ->orderBy('id')->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();
        return view('inventory.transfers.show', compact('transfer', 'items', 'search'));
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
