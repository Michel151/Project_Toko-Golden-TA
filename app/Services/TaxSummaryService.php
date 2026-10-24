<?php

namespace App\Services;

use App\Models\MonthlyTax;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

class TaxSummaryService
{
    public function build(string $from, string $to): array
    {
        $months = [];
        for ($date = CarbonImmutable::parse($from)->startOfMonth(); $date->lte(CarbonImmutable::parse($to)); $date = $date->addMonth()) {
            $months[] = $date->format('Y-m');
        }
        $records = MonthlyTax::whereIn('month', $months)->get()->keyBy('month');
        $missing = []; $total = BigDecimal::of('0.00');
        foreach ($months as $month) {
            $record = $records->get($month);
            if (!$record || $record->status !== 'confirmed') {
                $missing[] = CarbonImmutable::createFromFormat('!Y-m', $month)->locale('id')->translatedFormat('F Y');
            } else {
                $total = $total->plus($record->amount);
            }
        }
        return ['complete' => !$missing, 'missing' => $missing, 'amount' => !$missing ? (string) $total : null,
            'status' => $missing ? 'Pajak belum dikonfirmasi: '.implode(', ', $missing).'.' : 'Pajak seluruh bulan pada periode ini telah dikonfirmasi.'];
    }
}
