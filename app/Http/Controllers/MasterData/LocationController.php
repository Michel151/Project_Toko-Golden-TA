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
    // Jika Gudang punya baris Roti (20) dan Susu (0), kolom Jenis Barang tetap menampilkan 2.
    public function index()
    {
        $locations = Location::withCount('stocks')->latest()->latest('id')->paginate(25)->withQueryString();
        return view('master.locations.index', compact('locations'));
    }

    // Buka form lokasi dalam mode tambah.
    public function create()
    {
        return view('master.locations.form');
    }

    // Simpan nama wajib dan deskripsi opsional setelah validasi.
    public function store(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|max:100|unique:locations,name',
            'description' => 'nullable|string|max:255',
        ]);
        Location::create($request->only('name', 'description'));
        return redirect()->route('master.locations.index')->with('success', 'Lokasi berhasil ditambahkan.');
    }

    // Isi form bersama dari objek lokasi yang ditemukan melalui ID pada URL.
    public function edit(Location $location)
    {
        return view('master.locations.form', compact('location'));
    }

    // Gudang 2 boleh mempertahankan namanya sendiri. Nama yang sudah dipakai lokasi lain ditolak.
    public function update(Request $request, Location $location)
    {
        $request->validate([
            'name'        => 'required|string|max:100|unique:locations,name,' . $location->id,
            'description' => 'nullable|string|max:255',
        ]);
        $location->update($request->only('name', 'description'));
        return redirect()->route('master.locations.index')->with('success', 'Lokasi berhasil diperbarui.');
    }

    // Saat tombol Hapus ditekan, cek apakah lokasi masih punya stok atau pernah dipakai dalam transaksi.
    // Proses yang memakai kunci stok yang sama menunggu sampai pemeriksaan dan penghapusan selesai.
    public function destroy(Location $location)
    {
        try {
            $result = DB::transaction(function () use ($location) {
                // Serialize with stock writers before checking and deleting zero-stock rows.
                app(MovingAverageCostService::class)->lock();
                $locked = Location::whereKey($location->id)->lockForUpdate()->firstOrFail();
                // Jika masih ada stok, 'stock' masuk ke $result. Di bawah, hasil itu dipakai untuk memilih pesan kesalahan.
                if ($locked->hasStock()) return 'stock';
                if ($locked->hasTransactionHistory()) return 'history';
                $locked->delete();
                return 'deleted';
            }, 3);
        } catch (QueryException $exception) {
            // A reference inserted concurrently must also produce a clear refusal.
            // Database mengirim kode kesalahan. Kode di bawah membantu mengenali penolakan karena lokasi masih dipakai.
            $state = $exception->errorInfo[0] ?? null;
            $code = (int) ($exception->errorInfo[1] ?? 0);
            $foreignKeyViolation = $state === '23503'
                || ($state === '23000' && ($code === 1451
                    || (in_array($code, [19, 787], true) && str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed'))));
            // Kalau database menolak penghapusan karena data lain masih menunjuk lokasi ini, tampilkan pesan riwayat.
            // Kesalahan database dengan penyebab lain diteruskan ke penanganan error Laravel.
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
