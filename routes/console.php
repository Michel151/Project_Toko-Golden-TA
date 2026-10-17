<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sales:cancel-overdue', function (\App\Services\HeldSaleService $service) {
    $this->info($service->cancelOverdue().' transaksi menunggu pembayaran dibatalkan.');
})->purpose('Batalkan transaksi yang melewati batas pembayaran pukul 18.00');

Schedule::command('sales:cancel-overdue')->everyMinute()->withoutOverlapping();
