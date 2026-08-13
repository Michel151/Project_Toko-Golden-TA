<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Location;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function index()
    {
        $locations = Location::withCount('stocks')->latest()->paginate(15);
        return view('master.locations.index', compact('locations'));
    }

    public function create()
    {
        return view('master.locations.form');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:100|unique:locations,name',
            'description' => 'nullable|string|max:255',
        ]);
        Location::create($request->only('name', 'description'));
        return redirect()->route('master.locations.index')->with('success', 'Lokasi berhasil ditambahkan.');
    }

    public function edit(Location $location)
    {
        return view('master.locations.form', compact('location'));
    }

    public function update(Request $request, Location $location)
    {
        $request->validate([
            'name'        => 'required|string|max:100|unique:locations,name,' . $location->id,
            'description' => 'nullable|string|max:255',
        ]);
        $location->update($request->only('name', 'description'));
        return redirect()->route('master.locations.index')->with('success', 'Lokasi berhasil diperbarui.');
    }

    public function destroy(Location $location)
    {
        if ($location->hasStock()) {
            return back()->with('error', 'Lokasi tidak bisa dihapus karena masih memiliki stok barang.');
        }
        $location->delete();
        return redirect()->route('master.locations.index')->with('success', 'Lokasi berhasil dihapus.');
    }
}
