<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class FinancialPeriod
{
    public static function resolve(Request $request): array
    {
        $now = CarbonImmutable::now('Asia/Jakarta');
        $request->merge([
            'period_type' => $request->input('period_type', 'monthly'),
            'month' => $request->input('month', $now->format('Y-m')),
            'year' => $request->input('year', $now->year),
            'quarter' => $request->input('quarter', $now->quarter),
        ]);
        $selected = $request->validate([
            'period_type' => ['required', 'in:monthly,quarterly,yearly'],
            'month' => ['exclude_unless:period_type,monthly', 'required', 'date_format:Y-m'],
            'year' => ['exclude_if:period_type,monthly', 'required', 'integer', 'between:1900,9999'],
            'quarter' => ['exclude_unless:period_type,quarterly', 'required', 'integer', 'between:1,4'],
        ]);
        $periodType = $selected['period_type'];
        $exportFilters = $selected;
        if ($periodType === 'monthly') {
            $start = CarbonImmutable::createFromFormat('!Y-m', $selected['month'], 'Asia/Jakarta');
            $end = $start->endOfMonth();
            $periodLabel = $start->locale('id')->translatedFormat('F Y');
        } else {
            $start = CarbonImmutable::create((int) $selected['year'], $periodType === 'quarterly' ? ((int) $selected['quarter'] - 1) * 3 + 1 : 1, 1, 0, 0, 0, 'Asia/Jakarta');
            $end = $periodType === 'quarterly' ? $start->endOfQuarter() : $start->endOfYear();
            $periodLabel = $periodType === 'quarterly' ? 'Kuartal '.$selected['quarter'].' '.$selected['year'] : 'Tahun '.$selected['year'];
        }
        $from = $start->toDateString();
        $to = $end->toDateString();
        $month = $selected['month'] ?? $now->format('Y-m');
        $year = (int) ($selected['year'] ?? $now->year);
        $quarter = (int) ($selected['quarter'] ?? $now->quarter);
        return compact('from', 'to', 'periodType', 'periodLabel', 'exportFilters', 'month', 'year', 'quarter');
    }
}
