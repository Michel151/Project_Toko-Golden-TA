<?php
// ─────────────────────────────────────────────
// app/Http/Controllers/MasterData/CategoryController.php
// ─────────────────────────────────────────────
namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    // Saat daftar dibuka, hitung berapa barang yang memakai setiap kategori.
    // Contoh: jika Roti dan Biskuit masuk kategori Makanan, products_count untuk Makanan adalah 2.
    public function index()
    {
        $categories = Category::withCount('products')->latest()->latest('id')->paginate(25)->withQueryString();
        return view('master.categories.index', compact('categories'));
    }

    // Klik Tambah Kategori membuka form kosong. Kategori baru dibuat saat tombol Simpan memanggil store().
    public function create()
    {
        return view('master.categories.form');
    }

    // Kalau nama kategori kosong atau sudah dipakai, tampilkan kesalahan sebelum menyimpan.
    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|max:100|unique:categories,name']);
        // only('name') mengambil nama saja. create() membuat satu baris baru di tabel categories.
        Category::create($request->only('name'));
        return redirect()->route('master.categories.index')->with('success', 'Kategori berhasil ditambahkan.');
    }

    // Contoh: /master/categories/7/edit membuka form berisi nama kategori ID 7.
    public function edit(Category $category)
    {
        return view('master.categories.form', compact('category'));
    }

    // Saat mengubah kategori ID 7, nama lama boleh dipakai jika tidak ada kategori lain dengan nama itu.
    public function update(Request $request, Category $category)
    {
        $request->validate(['name' => 'required|string|max:100|unique:categories,name,' . $category->id]);
        // $category sudah menunjuk baris yang dipilih. update() mengganti nama pada baris itu.
        $category->update($request->only('name'));
        return redirect()->route('master.categories.index')->with('success', 'Kategori berhasil diperbarui.');
    }

    // Contoh: kategori Makanan tidak bisa dihapus selama Roti masih memakai kategori itu.
    public function destroy(Category $category)
    {
        if ($category->products()->exists()) {
            return back()->with('error', 'Kategori tidak bisa dihapus karena masih digunakan oleh barang.');
        }
        $category->delete();
        return redirect()->route('master.categories.index')->with('success', 'Kategori berhasil dihapus.');
    }
}
