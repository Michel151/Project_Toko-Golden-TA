<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    // Menyiapkan daftar satuan dan menunjukkan berapa barang yang memakainya.
    public function index()
    {
        $units = Unit::withCount([
            'productsAsBuyUnit as buy_unit_count',
            'productsAsRetailUnit as retail_unit_count',
        ])->latest()->latest('id')->paginate(25)->withQueryString();
        return view('master.units.index', compact('units'));
    }

    // Membuka form tambah satuan.
    public function create()
    {
        return view('master.units.form');
    }

    // Membuat satuan baru dari nama yang diketik pengguna.
    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|max:50|unique:units,name']);
        Unit::create($request->only('name'));
        return redirect()->route('master.units.index')->with('success', 'Satuan berhasil ditambahkan.');
    }

    // Membuka form ubah untuk satuan yang dipilih.
    public function edit(Unit $unit)
    {
        return view('master.units.form', compact('unit'));
    }

    // Menyimpan perubahan nama satuan.
    public function update(Request $request, Unit $unit)
    {
        $request->validate(['name' => 'required|string|max:50|unique:units,name,' . $unit->id]);
        $unit->update($request->only('name'));
        return redirect()->route('master.units.index')->with('success', 'Satuan berhasil diperbarui.');
    }

    // Menghapus satuan jika tidak ada barang yang masih memakainya.
    public function destroy(Unit $unit)
    {
        if ($unit->isUsed()) {
            return back()->with('error', 'Satuan tidak bisa dihapus karena masih digunakan oleh barang.');
        }
        $unit->delete();
        return redirect()->route('master.units.index')->with('success', 'Satuan berhasil dihapus.');
    }
}
