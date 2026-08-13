<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class ReportExport implements FromArray, ShouldAutoSize, WithEvents, WithTitle
{
    public function __construct(
        protected string $reportTitle,
        protected array $headings,
        protected array $rows,
        protected array $currencyColumns = [],
        protected array $decimalColumns = [],
        protected string $sheetTitle = 'Laporan'
    ) {}

    public function array(): array
    {
        return [
            [$this->reportTitle],
            ['Diekspor pada ' . now()->translatedFormat('d F Y H:i') . ' WIB'],
            [],
            $this->headings,
            ...$this->rows,
        ];
    }

    public function title(): string
    {
        return mb_substr($this->sheetTitle, 0, 31);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = Coordinate::stringFromColumnIndex(count($this->headings));
                $lastRow = max(4, count($this->rows) + 4);

                $sheet->mergeCells("A1:{$lastColumn}1");
                $sheet->mergeCells("A2:{$lastColumn}2");
                $sheet->freezePane('A5');
                $sheet->setAutoFilter("A4:{$lastColumn}{$lastRow}");
                $sheet->getRowDimension(1)->setRowHeight(28);
                $sheet->getRowDimension(4)->setRowHeight(24);

                $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'C8960C']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '141929']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getStyle("A2:{$lastColumn}2")->applyFromArray([
                    'font' => ['italic' => true, 'size' => 9, 'color' => ['rgb' => '6B7280']],
                ]);
                $sheet->getStyle("A4:{$lastColumn}4")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '27324A']],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['bottom' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => 'C8960C']]],
                ]);
                $sheet->getStyle("A5:{$lastColumn}{$lastRow}")->getBorders()->getBottom()
                    ->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB('E5E7EB');

                foreach ($this->currencyColumns as $columnIndex) {
                    $column = Coordinate::stringFromColumnIndex($columnIndex);
                    $sheet->getStyle("{$column}5:{$column}{$lastRow}")->getNumberFormat()
                        ->setFormatCode('[$Rp-421] #,##0');
                }
                foreach ($this->decimalColumns as $columnIndex) {
                    $column = Coordinate::stringFromColumnIndex($columnIndex);
                    $sheet->getStyle("{$column}5:{$column}{$lastRow}")->getNumberFormat()
                        ->setFormatCode('#,##0.00');
                }

                $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            },
        ];
    }
}
