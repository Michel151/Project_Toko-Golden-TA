<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductLocation;
use App\Models\Stock;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use App\Services\StockService;
use App\Services\OpnameExpiryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        $users = User::orderBy('name')->orderBy('id')->get(['id', 'name', 'role']);
        return view('inventory.opnames.create', compact('locations', 'users'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'location_id'  => 'required|exists:locations,id',
            'opname_date'  => 'required|date',
            'participant_ids' => 'sometimes|required|array|min:1|max:100',
            'participant_ids.*' => 'required|integer|distinct|exists:users,id',
        ]);

        // Buat draft opname dengan semua produk di lokasi tersebut
        $opname = DB::transaction(function () use ($request, $validated) {
            $participantIds = $validated['participant_ids'] ?? [auth()->id()];
            $participants = User::whereIn('id', $participantIds)->orderBy('name')->orderBy('id')->get(['id', 'name']);
            if ($participants->count() !== count($participantIds)) {
                throw ValidationException::withMessages(['participant_ids'=>'Daftar petugas berubah. Silakan pilih kembali petugas opname.']);
            }
            $opname = StockOpname::create([
                'opname_number' => StockOpname::generateOpnameNumber(),
                'location_id'   => $request->location_id,
                'opname_date'   => $request->opname_date,
                'status'        => 'draft',
                'created_by'    => auth()->id(),
                'participants'  => $participants->map(fn ($user) => ['user_id'=>$user->id, 'name'=>$user->name])->all(),
            ]);

            $registeredProductIds = ProductLocation::where('location_id', $request->location_id)
                ->pluck('product_id');
            $stockProductIds = Stock::where('location_id', $request->location_id)
                ->pluck('product_id');
            $productIds = $registeredProductIds
                ->merge($stockProductIds)
                ->unique()
                ->values();

            $stocks = Stock::where('location_id', $request->location_id)
                ->whereIn('product_id', $productIds)
                ->get()
                ->keyBy('product_id');

            $products = Product::whereIn('id', $productIds)
                ->orderBy('name')
                ->get();

            foreach ($products as $product) {
                $systemQuantity = (float) ($stocks->get($product->id)?->quantity ?? 0);
                $item = StockOpnameItem::create([
                    'stock_opname_id'   => $opname->id,
                    'product_id'        => $product->id,
                    'system_quantity'   => $systemQuantity,
                    'physical_quantity' => $systemQuantity, // default sama, nanti diisi
                    'difference'        => 0,
                ]);
                app(OpnameExpiryService::class)->capture($item, (int) $opname->location_id);
            }

            return $opname;
        });

        return redirect()->route(auth()->user()->hasPermission('inventory.opnames.edit')
            ? 'inventory.opnames.edit' : 'inventory.opnames.show', $opname)
                         ->with('success', 'Draft stock opname dibuat. Isi jumlah fisik setiap barang.');
    }

    public function edit(StockOpname $opname)
    {
        if ($opname->status === 'completed') {
            return redirect()->route('inventory.opnames.show', $opname)
                             ->with('error', 'Opname sudah selesai, tidak bisa diedit.');
        }

        try {
            DB::transaction(function () use ($opname) {
                $locked = $this->lockDraft($opname);
                foreach ($locked->items as $item) app(OpnameExpiryService::class)->capture($item, (int) $locked->location_id);
            });
        } catch (ValidationException $exception) {
            return redirect()->route('inventory.opnames.index')->with('error', collect($exception->errors())->flatten()->first());
        }
        $opname->load(['location', 'items.expiryCounts', 'items.product.buyUnit', 'items.product.retailUnit', 'items.product.baseProductUnit.unit', 'items.product.productUnits.unit']);
        return view('inventory.opnames.edit', compact('opname'));
    }

    public function update(Request $request, StockOpname $opname)
    {
        if ($opname->status === 'completed') {
            return back()->with('error', 'Opname sudah selesai.');
        }

        $validated = $this->validateItems($request);

        DB::transaction(function () use ($opname, $validated) {
            app(\App\Services\MovingAverageCostService::class)->lock();
            $this->savePhysicalQuantities($this->lockDraft($opname), $validated['items']);
        });

        return back()->with('success', 'Data opname berhasil disimpan.');
    }

    public function show(Request $request, StockOpname $opname)
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:150',
            'filter' => 'nullable|in:all,changed,short,over,matched',
            'per_page' => 'nullable|integer|in:25,50,100',
        ]);
        $search = trim($filters['q'] ?? '');
        $filter = $filters['filter'] ?? 'all';
        $perPage = (int) ($filters['per_page'] ?? 25);
        $opname->load(['location', 'createdBy']);
        $changed = fn ($query) => $query->where('difference', '!=', 0)
            ->orWhereHas('expiryCounts', fn ($dates) => $dates->whereColumn('physical_quantity', '!=', 'system_quantity'));
        $totalItems = $opname->items()->count();
        $changedItems = $opname->items()->where($changed)->count();
        $summary = ['total'=>$totalItems, 'changed'=>$changedItems, 'matched'=>$totalItems-$changedItems];
        $query = $opname->items()->with(['expiryCounts', 'product.buyUnit', 'product.retailUnit', 'product.productUnits.unit']);
        if ($search !== '') $query->whereHas('product', fn ($products) => $products->where('name', 'like', '%'.$search.'%'));
        if ($filter === 'changed') $query->where($changed);
        if ($filter === 'short') $query->where('difference', '<', 0);
        if ($filter === 'over') $query->where('difference', '>', 0);
        if ($filter === 'matched') $query->where('difference', 0)
            ->whereDoesntHave('expiryCounts', fn ($dates) => $dates->whereColumn('physical_quantity', '!=', 'system_quantity'));
        $items = $query->orderBy(Product::select('name')->whereColumn('products.id', 'stock_opname_items.product_id'))
            ->orderBy('id')->paginate($perPage)->withQueryString();
        return view('inventory.opnames.show', compact('opname', 'items', 'summary', 'search', 'filter', 'perPage'));
    }

    // Selesaikan opname — update stok sesuai fisik
    public function complete(Request $request, StockOpname $opname)
    {
        if ($opname->status === 'completed') {
            return back()->with('error', 'Opname sudah selesai.');
        }

        $validated = $this->validateItems($request);

        DB::transaction(function () use ($opname, $validated) {
            app(\App\Services\MovingAverageCostService::class)->lock();
            $opname = $this->lockDraft($opname);
            $this->savePhysicalQuantities($opname, $validated['items']);
            $opname->load(['items.product', 'location']);

            foreach ($opname->items as $item) {
                if ($item->tracks_expiry) {
                    app(OpnameExpiryService::class)->apply($item, $opname, $this->stockService);
                    continue;
                }
                $stock = Stock::where('product_id', $item->product_id)->where('location_id', $opname->location_id)->lockForUpdate()->first();
                if (abs((float) ($stock?->quantity ?? 0) - (float) $item->system_quantity) > .00001) {
                    throw ValidationException::withMessages(['items'=>'Stok '.$item->product->name.' berubah sejak draf dibuat. Buat draf opname baru sebelum menyesuaikan stok.']);
                }
                // Update stok sesuai hasil fisik
                $this->stockService->adjustStock(
                    $item->product,
                    $opname->location,
                    $item->physical_quantity,
                    $opname,
                    $opname->opname_date,
                    $opname->opname_number,
                    $item->valuation_unit_cost
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

    private function validateItems(Request $request): array
    {
        return $request->validate([
            'items' => 'required|array|max:10000',
            'items.*' => 'required|array:physical_quantity,expiries,valuation_unit_cost',
            'items.*.valuation_unit_cost' => 'nullable|numeric|min:0|max:999999999999.99|decimal:0,10',
            'items.*.physical_quantity' => 'nullable|numeric|decimal:0,4|min:0|max:99999999999.9999',
            'items.*.expiries' => 'nullable|array|max:500',
            'items.*.expiries.*' => 'required|array:id,expired_date,physical_quantity',
            'items.*.expiries.*.id' => 'nullable|integer|min:1',
            'items.*.expiries.*.expired_date' => 'nullable|date_format:Y-m-d',
            'items.*.expiries.*.physical_quantity' => 'required|numeric|decimal:0,4|min:0|max:99999999999.9999',
        ]);
    }

    private function savePhysicalQuantities(StockOpname $opname, array $items): void
    {
        $opnameItems = $opname->items()->with('product')->lockForUpdate()->get()->keyBy('id');
        $expected=$opnameItems->keys()->map(fn ($id)=>(string)$id)->sort()->values()->all();
        $submitted=collect(array_keys($items))->map(fn ($id)=>(string)$id)->sort()->values()->all();
        if ($expected!==$submitted) {
            throw ValidationException::withMessages(['items'=>'Semua barang dalam opname wajib disertakan, tanpa barang dari opname lain.']);
        }

        foreach ($items as $itemId => $data) {
            $item = $opnameItems->get((int) $itemId);
            if (auth()->user()?->isOwner()) $item->update(['valuation_unit_cost' => $data['valuation_unit_cost'] ?? $item->valuation_unit_cost]);
            app(OpnameExpiryService::class)->capture($item, (int) $opname->location_id);
            if ((bool)$item->tracks_expiry !== (bool)$item->product->is_expirable) {
                throw ValidationException::withMessages(['items'=>'Pengaturan kedaluwarsa barang berubah. Buat draf opname baru.']);
            }
            if ($item->tracks_expiry) {
                app(OpnameExpiryService::class)->saveCounts($item, $data);
                continue;
            }
            if (!isset($data['physical_quantity']) || !empty($data['expiries'])) {
                throw ValidationException::withMessages(['items'=>'Isi stok fisik barang tanpa rincian kedaluwarsa.']);
            }

            $physicalQuantity = (float) $data['physical_quantity'];

            $item->update([
                'physical_quantity' => $physicalQuantity,
                'difference'        => $physicalQuantity - (float) $item->system_quantity,
            ]);
        }
    }

    private function lockDraft(StockOpname $opname): StockOpname
    {
        $locked=StockOpname::lockForUpdate()->findOrFail($opname->id);
        if ($locked->status!=='draft') {
            throw ValidationException::withMessages(['items'=>'Opname sudah selesai dan tidak dapat diubah.']);
        }
        return $locked;
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
