<?php

namespace App\Services;

use App\Models\MonthlyTax;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

class TaxSummaryService
{
    // Menjumlahkan pajak periode laporan dan memastikan setiap bulan sudah dikonfirmasi.
    public function build(string $from, string $to): array
    {
        // Mendaftar semua bulan yang perlu diperiksa, termasuk bulan yang belum dicatat.
        $months = [];
        for ($date = CarbonImmutable::parse($from)->startOfMonth(); $date->lte(CarbonImmutable::parse($to)); $date = $date->addMonth()) {
            $months[] = $date->format('Y-m');
        }
        // Mengambil catatan sekaligus dan menyusunnya berdasarkan bulan.
        $records = MonthlyTax::whereIn('month', $months)->get()->keyBy('month');
        $missing = []; $total = BigDecimal::of('0.00');
        // Bulan tanpa catatan atau yang masih draf masuk daftar pajak belum lengkap.
        foreach ($months as $month) {
            $record = $records->get($month);
            if (!$record || $record->status !== 'confirmed') {
                $missing[] = CarbonImmutable::createFromFormat('!Y-m', $month)->locale('id')->translatedFormat('F Y');
            } else {
                $total = $total->plus($record->amount);
            }
        }
        // Total dikembalikan sebagai null jika ada bulan yang belum dikonfirmasi.
        return ['complete' => !$missing, 'missing' => $missing, 'amount' => !$missing ? (string) $total : null,
            'status' => $missing ? 'Pajak belum dikonfirmasi: '.implode(', ', $missing).'.' : 'Pajak seluruh bulan pada periode ini telah dikonfirmasi.'];
    }
}
