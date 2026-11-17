<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    // Cari supplier lewat nama, telepon, atau alamat; hitung PO dan pertahankan pencarian saat pindah halaman.
    public function index(Request $request)
    {
        $search = $request->query('q');
        $search = is_string($search) ? trim($search) : '';

        $suppliers = Supplier::withCount('purchaseOrders')
            ->when($search !== '', function ($query) use ($search) {
                $pattern = '%'.$search.'%';

                $query->where(fn ($supplier) => $supplier->whereLike('name', $pattern)
                    // Cukup satu kolom cocok. Contoh: mencari 0812 bisa menemukan telepon 08123456789.
                    ->orWhereLike('phone', $pattern)
                    ->orWhereLike('address', $pattern));
            })
            ->latest()
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('master.suppliers.index', compact('suppliers', 'search'));
    }

    // Muat pilihan barang beserta kategori dan satuan belinya untuk form supplier baru.
    public function create()
    {
        $products = Product::with(['buyUnit', 'category'])->orderBy('name')->get();

        return view('master.suppliers.form', compact('products'));
    }

    // Simpan nama, alamat, dan telepon di suppliers. Simpan barang yang dipilih beserta harganya di product_supplier.
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

    // Jika tab Barang dibuka, $rows berisi barang supplier. Jika tab Riwayat PO dibuka dan diizinkan,
    // $rows berisi pesanan pembelian dari supplier yang sama.
    public function show(Request $request, Supplier $supplier)
    {
        $canViewOrders = $request->user()->hasPermission('purchase.orders.view');
        $activeTab = $canViewOrders && $request->query('tab') === 'orders' ? 'orders' : 'products';
        $search = $request->query('q');
        $search = is_string($search) ? trim($search) : '';
        $searchParams = $search === '' ? [] : ['q' => $search];

        if ($activeTab === 'orders') {
            $rows = $supplier->purchaseOrders()
                ->with('items')
                ->latest('order_date')
                ->latest('created_at')
                ->latest('id')
                ->paginate(25)
                ->appends(['tab' => 'orders'] + $searchParams);
        } else {
            $rows = $supplier->products()
                ->with('buyUnit')
                ->when($search !== '', fn ($query) => $query->whereLike('products.name', '%'.$search.'%'))
                ->orderBy('products.name')
                ->orderBy('products.id')
                ->paginate(25)
                ->appends($searchParams);
        }

        $productCount = $activeTab === 'products' && $search === '' ? $rows->total() : $supplier->products()->count();

        return view('master.suppliers.show', compact('supplier', 'rows', 'productCount', 'canViewOrders', 'activeTab', 'search', 'searchParams'));
    }

    // Ambil barang yang sudah dipasok agar kotaknya tercentang saat edit. Setelah itu ambil juga pilihan barang lainnya.
    public function edit(Supplier $supplier)
    {
        $supplier->load('products');
        $products = Product::with(['buyUnit', 'category'])->orderBy('name')->get();

        return view('master.suppliers.form', compact('supplier', 'products'));
    }

    // Simpan kontak baru dan sesuaikan barang yang dipasok dengan centang terakhir pada form.
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

    // Jika supplier sudah memiliki pesanan pembelian, batalkan hapus agar riwayat masih bisa menampilkan suppliernya.
    public function destroy(Supplier $supplier)
    {
        if ($supplier->purchaseOrders()->exists()) {
            return back()->with('error', 'Supplier tidak bisa dihapus karena memiliki riwayat pembelian.');
        }
        $supplier->delete();
        return redirect()->route('master.suppliers.index')->with('success', 'Supplier berhasil dihapus.');
    }

    // Simpan pilihan barang yang dicentang. Jika Gula dilepas centangnya, hubungan Gula dengan supplier ini dihapus.
    // Barang Gula masih ada di Master Barang.
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

    // Rapikan harga pada setiap baris barang, lalu masukkan hasilnya kembali ke request sebelum validasi.
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

    // Tafsirkan pemisah angka agar masukan seperti 14.500,50 bisa diperiksa sebagai 14500.50.
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
