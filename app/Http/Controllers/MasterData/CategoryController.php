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
    public function index()
    {
        $categories = Category::withCount('products')->latest()->latest('id')->paginate(15);
        return view('master.categories.index', compact('categories'));
    }

    public function create()
    {
        return view('master.categories.form');
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|max:100|unique:categories,name']);
        Category::create($request->only('name'));
        return redirect()->route('master.categories.index')->with('success', 'Kategori berhasil ditambahkan.');
    }

    public function edit(Category $category)
    {
        return view('master.categories.form', compact('category'));
    }

    public function update(Request $request, Category $category)
    {
        $request->validate(['name' => 'required|string|max:100|unique:categories,name,' . $category->id]);
        $category->update($request->only('name'));
        return redirect()->route('master.categories.index')->with('success', 'Kategori berhasil diperbarui.');
    }

    public function destroy(Category $category)
    {
        if ($category->products()->exists()) {
            return back()->with('error', 'Kategori tidak bisa dihapus karena masih digunakan oleh barang.');
        }
        $category->delete();
        return redirect()->route('master.categories.index')->with('success', 'Kategori berhasil dihapus.');
    }
}
