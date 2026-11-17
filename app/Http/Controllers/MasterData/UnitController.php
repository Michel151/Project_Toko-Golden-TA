<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    // Hitung jumlah produk yang menggunakan satuan ini sebagai satuan beli dan ecer secara terpisah.
    public function index()
    {
        $units = Unit::withCount([
            // as memberi nama pada hasil hitung. View membaca angka ini lewat $unit->buy_unit_count.
            'productsAsBuyUnit as buy_unit_count',
            'productsAsRetailUnit as retail_unit_count',
        ])->latest()->latest('id')->paginate(25)->withQueryString();
        return view('master.units.index', compact('units'));
    }

    // Form tanpa objek satuan akan mengirim isian ke store().
    public function create()
    {
        return view('master.units.form');
    }

    // Validasi nama satuan yang unik sebelum membuat baris baru.
    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|max:50|unique:units,name']);
        Unit::create($request->only('name'));
        return redirect()->route('master.units.index')->with('success', 'Satuan berhasil ditambahkan.');
    }

    // Kirim satuan dari ID pada URL agar nama lama tersedia di form.
    public function edit(Unit $unit)
    {
        return view('master.units.form', compact('unit'));
    }

    // Jaga nama tetap unik terhadap satuan lain, lalu simpan perubahan pada satuan yang dipilih.
    public function update(Request $request, Unit $unit)
    {
        $request->validate(['name' => 'required|string|max:50|unique:units,name,' . $unit->id]);
        $unit->update($request->only('name'));
        return redirect()->route('master.units.index')->with('success', 'Satuan berhasil diperbarui.');
    }

    // Pak bisa tampil dengan angka 0 untuk Beli dan Ecer, tetapi masih dipakai sebagai pilihan kemasan suatu barang.
    // isUsed() juga memeriksa penggunaan ini sebelum satuan dihapus.
    public function destroy(Unit $unit)
    {
        if ($unit->isUsed()) {
            return back()->with('error', 'Satuan tidak bisa dihapus karena masih digunakan oleh barang.');
        }
        $unit->delete();
        return redirect()->route('master.units.index')->with('success', 'Satuan berhasil dihapus.');
    }
}
