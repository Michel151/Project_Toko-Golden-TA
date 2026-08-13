<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index()
    {
        $suppliers = Supplier::withCount('purchaseOrders')->latest()->paginate(15);
        return view('master.suppliers.index', compact('suppliers'));
    }

    public function create()
    {
        $products = Product::with(['buyUnit', 'category'])->orderBy('name')->get();

        return view('master.suppliers.form', compact('products'));
    }

    public function store(Request $request)
    {
        $this->normalizeProductPrices($request);

        $validated = $request->validate([
            'name'    => 'required|string|max:150',
            'address' => 'nullable|string',
            'phone'   => 'nullable|string|max:20',
            'products' => 'nullable|array',
            'products.*.selected' => 'nullable|boolean',
            'products.*.buy_price' => 'nullable|numeric|min:0',
        ]);

        $supplier = Supplier::create($request->only('name', 'address', 'phone'));
        $this->syncProducts($supplier, $validated['products'] ?? []);

        return redirect()->route('master.suppliers.index')->with('success', 'Supplier berhasil ditambahkan.');
    }

    public function show(Supplier $supplier)
    {
        $supplier->load(['products.buyUnit']);
        $purchaseOrders = $supplier->purchaseOrders()
            ->with('items.product')
            ->latest()
            ->paginate(10);
        return view('master.suppliers.show', compact('supplier', 'purchaseOrders'));
    }

    public function edit(Supplier $supplier)
    {
        $supplier->load('products');
        $products = Product::with(['buyUnit', 'category'])->orderBy('name')->get();

        return view('master.suppliers.form', compact('supplier', 'products'));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $this->normalizeProductPrices($request);

        $validated = $request->validate([
            'name'    => 'required|string|max:150',
            'address' => 'nullable|string',
            'phone'   => 'nullable|string|max:20',
            'products' => 'nullable|array',
            'products.*.selected' => 'nullable|boolean',
            'products.*.buy_price' => 'nullable|numeric|min:0',
        ]);
        $supplier->update($request->only('name', 'address', 'phone'));
        $this->syncProducts($supplier, $validated['products'] ?? []);

        return redirect()->route('master.suppliers.index')->with('success', 'Supplier berhasil diperbarui.');
    }

    public function destroy(Supplier $supplier)
    {
        if ($supplier->purchaseOrders()->exists()) {
            return back()->with('error', 'Supplier tidak bisa dihapus karena memiliki riwayat pembelian.');
        }
        $supplier->delete();
        return redirect()->route('master.suppliers.index')->with('success', 'Supplier berhasil dihapus.');
    }

    private function syncProducts(Supplier $supplier, array $products): void
    {
        $syncData = [];
        $validProductIds = Product::whereIn('id', array_keys($products))
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => true]);

        foreach ($products as $productId => $productData) {
            if (! empty($productData['selected']) && $validProductIds->has($productId)) {
                $syncData[$productId] = [
                    'buy_price' => $productData['buy_price'] ?? 0,
                ];
            }
        }

        $supplier->products()->sync($syncData);
    }

    private function normalizeProductPrices(Request $request): void
    {
        $products = $request->input('products', []);

        foreach ($products as $productId => $productData) {
            if (array_key_exists('buy_price', $productData)) {
                $products[$productId]['buy_price'] = $this->normalizeCurrency($productData['buy_price']);
            }
        }

        $request->merge(['products' => $products]);
    }

    private function normalizeCurrency(mixed $value): string
    {
        $value = preg_replace('/[^\d,\.]/', '', (string) $value);

        if (str_contains($value, ',') && str_contains($value, '.')) {
            return strrpos($value, ',') > strrpos($value, '.')
                ? str_replace(',', '.', str_replace('.', '', $value))
                : str_replace(',', '', $value);
        }

        if (preg_match('/^\d+\.\d{1,2}$/', $value)) {
            return $value;
        }

        if (preg_match('/^\d+,\d{1,2}$/', $value)) {
            return str_replace(',', '.', $value);
        }

        return str_replace(['.', ','], '', $value);
    }
}
