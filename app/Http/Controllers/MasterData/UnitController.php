<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    public function index()
    {
        $units = Unit::withCount([
            'productsAsBuyUnit as buy_unit_count',
            'productsAsRetailUnit as retail_unit_count',
        ])->latest()->paginate(15);
        return view('master.units.index', compact('units'));
    }

    public function create()
    {
        return view('master.units.form');
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|max:50|unique:units,name']);
        Unit::create($request->only('name'));
        return redirect()->route('master.units.index')->with('success', 'Satuan berhasil ditambahkan.');
    }

    public function edit(Unit $unit)
    {
        return view('master.units.form', compact('unit'));
    }

    public function update(Request $request, Unit $unit)
    {
        $request->validate(['name' => 'required|string|max:50|unique:units,name,' . $unit->id]);
        $unit->update($request->only('name'));
        return redirect()->route('master.units.index')->with('success', 'Satuan berhasil diperbarui.');
    }

    public function destroy(Unit $unit)
    {
        if ($unit->isUsed()) {
            return back()->with('error', 'Satuan tidak bisa dihapus karena masih digunakan oleh barang.');
        }
        $unit->delete();
        return redirect()->route('master.units.index')->with('success', 'Satuan berhasil dihapus.');
    }
}
