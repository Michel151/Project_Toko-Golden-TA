<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Services\MovingAverageCostService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocationController extends Controller
{
    // Menampilkan daftar lokasi penyimpanan beserta jumlah jenis barang yang tercatat.
    public function index()
    {
        $locations = Location::withCount('stocks')->latest()->latest('id')->paginate(25)->withQueryString();
        return view('master.locations.index', compact('locations'));
    }

    // Membuka form tambah lokasi.
    public function create()
    {
        return view('master.locations.form');
    }

    // Membuat lokasi penyimpanan baru.
    public function store(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:100|unique:locations,name',
            'description' => 'nullable|string|max:255',
        ]);
        Location::create($request->only('name', 'description'));
        return redirect()->route('master.locations.index')->with('success', 'Lokasi berhasil ditambahkan.');
    }

    // Membuka form ubah untuk lokasi yang dipilih.
    public function edit(Location $location)
    {
        return view('master.locations.form', compact('location'));
    }

    // Menyimpan perubahan nama dan deskripsi lokasi.
    public function update(Request $request, Location $location)
    {
        $request->validate([
            'name'        => 'required|string|max:100|unique:locations,name,' . $location->id,
            'description' => 'nullable|string|max:255',
        ]);
        $location->update($request->only('name', 'description'));
        return redirect()->route('master.locations.index')->with('success', 'Lokasi berhasil diperbarui.');
    }

    // Menghapus lokasi yang sudah kosong dan tidak dipakai dalam riwayat.
    public function destroy(Location $location)
    {
        try {
            $result = DB::transaction(function () use ($location) {
                // Serialize with stock writers before checking and deleting zero-stock rows.
                app(MovingAverageCostService::class)->lock();
                $locked = Location::whereKey($location->id)->lockForUpdate()->firstOrFail();
                if ($locked->hasStock()) return 'stock';
                if ($locked->hasTransactionHistory()) return 'history';
                $locked->delete();
                return 'deleted';
            }, 3);
        } catch (QueryException $exception) {
            // A reference inserted concurrently must also produce a clear refusal.
            $state = $exception->errorInfo[0] ?? null;
            $code = (int) ($exception->errorInfo[1] ?? 0);
            $foreignKeyViolation = $state === '23503'
                || ($state === '23000' && ($code === 1451
                    || (in_array($code, [19, 787], true) && str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed'))));
            // Gunakan pesan riwayat jika database menolak penghapusan lokasi yang masih dipakai.
            if (!$foreignKeyViolation || !preg_match('/^delete\s+from\s+[`"\[]?locations\b/i', $exception->getSql())) {
                throw $exception;
            }
            $result = 'history';
        }

        if ($result === 'stock') {
            return back()->with('error', 'Lokasi tidak bisa dihapus karena masih memiliki stok barang.');
        }
        if ($result === 'history') {
            return back()->with('error', 'Lokasi tidak dapat dihapus karena masih digunakan dalam riwayat transaksi. Riwayat penerimaan, penjualan, transfer, opname, batch stok, atau penahanan barang harus tetap tersimpan.');
        }
        return redirect()->route('master.locations.index')->with('success', 'Lokasi berhasil dihapus.');
    }
}
