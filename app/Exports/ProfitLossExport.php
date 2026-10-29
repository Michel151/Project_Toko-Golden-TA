<?php

namespace App\Exports;

use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ProfitLossExport extends ReportExport implements WithStrictNullComparison
{
    public function __construct(string $title, private array $lines, array $metadata, string $sheetTitle = 'Laba Rugi', string $missing = 'Belum lengkap')
    {
        $rows = array_map(fn ($line) => [$line[0], ($line[3] ?? 'detail') === 'section' ? null : ($line[1] ?? $missing)], $lines);
        parent::__construct($title, ['Uraian', 'Nilai (Rp)'], $rows, [], [2], $sheetTitle, $metadata);
    }

    public function registerEvents(): array
    {
        $base = parent::registerEvents()[AfterSheet::class];
        return [AfterSheet::class => function (AfterSheet $event) use ($base) {
            $base($event);
            $sheet = $event->sheet->getDelegate();
            $sheet->getColumnDimension('A')->setWidth(66);
            $sheet->getColumnDimension('B')->setWidth(25);
            $sheet->getRowDimension(3)->setRowHeight(max(45, ceil(mb_strlen(implode(' | ', $this->metadata)) / 85) * 13 + 12));
            $sheet->getAutoFilter()->setRange('');
            $last = count($this->rows) + 4;
            $sheet->getStyle('B5:B'.$last)->getNumberFormat()->setFormatCode('#,##0;(#,##0);0');
            foreach ($this->lines as $index => $line) {
                $row = $index + 5;
                $sheet->getRowDimension($row)->setRowHeight(22);
                $style = $sheet->getStyle('A'.$row.':B'.$row);
                if ($line[2]) $style->getFont()->setBold(true);
                $kind = $line[3] ?? 'detail';
                if ($kind === 'section') {
                    $sheet->mergeCells('A'.$row.':B'.$row);
                    $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EFF3EE');
                    $sheet->getRowDimension($row)->setRowHeight(24);
                } elseif (in_array($kind, ['total', 'result'], true) || $line[2]) {
                    $style->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
                    if ($kind === 'result') $style->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);
                }
            }
        }];
    }
}
