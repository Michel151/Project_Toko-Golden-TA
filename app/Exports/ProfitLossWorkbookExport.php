<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ProfitLossWorkbookExport implements WithMultipleSheets
{
    // Menerima hasil perhitungan laporan untuk seluruh sheet Excel.
    public function __construct(private array $data) {}

    // Membuat dua sheet: Laba Rugi dan Rekonsiliasi Persediaan.
    public function sheets(): array
    {
        $data = $this->data;
        $period = 'Periode: '.\Carbon\CarbonImmutable::parse($data['from'])->format('d/m/Y')
            .' s.d. '.\Carbon\CarbonImmutable::parse($data['to'])->format('d/m/Y');
        // Menyertakan peringatan HPP atau pajak yang belum lengkap dalam ekspor.
        $information = ['Nominal disajikan dalam rupiah penuh, dibulatkan ke rupiah terdekat.'];
        if ($data['incompleteCount'] > 0) {
            $information[] = 'Catatan: HPP pada '.$data['incompleteCount'].' transaksi penjualan belum lengkap; laba belum dapat ditentukan secara lengkap.';
        }
        if (!$data['taxSummary']['complete']) {
            $information[] = 'Catatan: Beban pajak belum dikonfirmasi untuk '.implode(', ', $data['taxSummary']['missing'])
                .'; laba bersih belum dapat ditentukan.';
        }
        return [
            new ProfitLossExport('Laporan Laba Rugi', $data['lines'], $information, reportPeriod: $data['periodLabel'].' | '.$period),
            new ProfitLossExport('Rekonsiliasi Persediaan', $data['reconciliation']['lines'], [
                'Nominal disajikan dalam rupiah penuh, dibulatkan ke rupiah terdekat.', 'Status: '.$data['reconciliation']['statusLabel'], ...$data['reconciliation']['notes'],
            ], 'Rekonsiliasi Persediaan', 'Belum tersedia', reportPeriod: $data['periodLabel'].' | '.$period),
        ];
    }
}
