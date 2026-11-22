<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Perintah ini membatalkan transaksi pending terlambat melalui service dan melaporkan jumlah yang berhasil.
Artisan::command('sales:cancel-overdue', function (\App\Services\HeldSaleService $service) {
    $this->info($service->cancelOverdue().' transaksi menunggu pembayaran dibatalkan.');
})->purpose('Batalkan transaksi yang melewati batas pembayaran pukul 18.00');

// Scheduler memeriksa setiap menit dan mencegah perintah yang sama berjalan bertumpuk.
Schedule::command('sales:cancel-overdue')->everyMinute()->withoutOverlapping();

// Perintah ini menutup draf lama sesuai batas harian tanpa membatalkan transaksi penjualan.
Artisan::command('sales:expire-drafts', function (\App\Services\SaleDraftService $service) {
    $this->info($service->expireOverdue().' draf keranjang kedaluwarsa ditutup.');
})->purpose('Tutup draf keranjang yang melewati batas harian pukul 18.00 WIB');

Schedule::command('sales:expire-drafts')->everyMinute()->withoutOverlapping();
