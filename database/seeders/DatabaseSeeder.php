<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Category;
use App\Models\Unit;
use App\Models\Location;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Users ────────────────────────────────────────────────────────────
        User::create([
            'name'     => 'Owner Golden',
            'email'    => 'owner@tokogolden.com',
            'password' => Hash::make('password'),
            'role'     => 'owner',
        ]);

        User::create([
            'name'     => 'Kasir 1',
            'email'    => 'kasir@tokogolden.com',
            'password' => Hash::make('password'),
            'role'     => 'kasir',
        ]);

        User::create([
            'name'     => 'Staf Gudang 1',
            'email'    => 'gudang@tokogolden.com',
            'password' => Hash::make('password'),
            'role'     => 'staf_gudang',
        ]);

        // ── Kategori ─────────────────────────────────────────────────────────
        $categories = [
            'Bahan Pokok',
            'Minuman',
            'Snack & Makanan Ringan',
            'Bumbu Dapur',
            'Produk Kebersihan',
            'Rokok',
            'Alat Tulis',
            'Lain-lain',
        ];

        foreach ($categories as $cat) {
            Category::create(['name' => $cat]);
        }

        // ── Satuan ───────────────────────────────────────────────────────────
        $units = [
            'Karung',
            'Dus',
            'Pak',
            'Kg',
            'Gram',
            'Liter',
            'Ml',
            'Botol',
            'Pcs',
            'Lusin',
            'Kodi',
            'Slop',
            'Bungkus',
            'Kaleng',
        ];

        foreach ($units as $unit) {
            Unit::create(['name' => $unit]);
        }

        // ── Lokasi ───────────────────────────────────────────────────────────
        Location::create([
            'name'        => 'Toko',
            'description' => 'Toko utama tempat penjualan',
        ]);

        Location::create([
            'name'        => 'Gudang 2',
            'description' => 'Mostly minuman dan sebagian barang lain',
        ]);

        Location::create([
            'name'        => 'Gudang 3',
            'description' => 'Penyimpanan utama stok besar semua jenis barang',
        ]);
    }
}
