<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class ReportExport implements FromArray, WithCustomValueBinder, WithEvents, WithTitle
{
    public function __construct(
        protected string $reportTitle,
        protected array $headings,
        protected array $rows,
        protected array $currencyColumns = [],
        protected array $decimalColumns = [],
        protected string $sheetTitle = 'Laporan',
        protected array $metadata = []
    ) {}

    public function array(): array
    {
        return [
            [$this->reportTitle],
            ['Toko Golden | Diekspor pada ' . now('Asia/Jakarta')->translatedFormat('d F Y H:i') . ' WIB'],
            [implode(' | ', $this->metadata)],
            $this->headings,
            ...$this->rows,
        ];
    }

    public function title(): string
    {
        return mb_substr($this->sheetTitle, 0, 31);
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);
            return true;
        }
        return (new DefaultValueBinder)->bindValue($cell, $value);
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = Coordinate::stringFromColumnIndex(count($this->headings));
                $dataLastRow = count($this->rows) + 4;
                $hasTotal = $this->rows && ($this->rows[array_key_last($this->rows)][0] ?? null) === 'TOTAL';
                $lastRow = max(5, $dataLastRow);
                if (! $hasTotal && $this->currencyColumns) {
                    $lastRow++;
                    $sheet->setCellValue("A{$lastRow}", 'TOTAL');
                    foreach ($this->currencyColumns as $columnIndex) {
                        $total = array_sum(array_map(fn ($row) => is_numeric($row[$columnIndex - 1] ?? null) ? (float) $row[$columnIndex - 1] : 0, $this->rows));
                        $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnIndex).$lastRow, $total);
                    }
                    $hasTotal = true;
                }
                $filterLastRow = $hasTotal && $dataLastRow === $lastRow ? $lastRow - 1 : $dataLastRow;

                $sheet->mergeCells("A1:{$lastColumn}1");
                $sheet->mergeCells("A2:{$lastColumn}2");
                $sheet->mergeCells("A3:{$lastColumn}3");
                $sheet->freezePane('A5');
                $sheet->setAutoFilter("A4:{$lastColumn}".max(4, $filterLastRow));
                $sheet->setShowGridlines(false);
                $sheet->setPrintGridlines(false);
                $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->applyFromArray([
                    'font' => ['name'=>'Arial', 'size'=>10, 'color'=>['rgb'=>'111111']],
                    'fill' => ['fillType'=>Fill::FILL_NONE],
                    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_LEFT, 'vertical'=>Alignment::VERTICAL_CENTER, 'wrapText'=>true, 'indent'=>1],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(36);
                $sheet->getRowDimension(2)->setRowHeight(20);
                $sheet->getRowDimension(3)->setRowHeight($this->metadata ? 26 : 10);
                $sheet->getRowDimension(4)->setRowHeight(32);

                $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => '111111']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getStyle("A2:{$lastColumn}2")->applyFromArray([
                    'font' => ['size' => 9, 'color' => ['rgb' => '555555']],
                ]);
                $sheet->getStyle("A3:{$lastColumn}3")->getFont()->setSize(9)->getColor()->setRGB('555555');
                $sheet->getStyle("A4:{$lastColumn}4")->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => '111111']],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => [
                        'top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '111111']],
                        'bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '111111']],
                    ],
                ]);
                $sheet->getStyle("A5:{$lastColumn}{$lastRow}")->getBorders()->getBottom()
                    ->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB('DDDDDD');

                if (! $this->rows) {
                    $sheet->mergeCells("A5:{$lastColumn}5");
                    $sheet->setCellValue('A5', 'Tidak ada data sesuai filter laporan.');
                    $sheet->getRowDimension(5)->setRowHeight(26);
                }
                if ($hasTotal) {
                    $sheet->getStyle("A{$lastRow}:{$lastColumn}{$lastRow}")->applyFromArray([
                        'font'=>['bold'=>true],
                        'borders'=>[
                            'top'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'111111']],
                            'bottom'=>['borderStyle'=>Border::BORDER_DOUBLE,'color'=>['rgb'=>'111111']],
                        ],
                    ]);
                }

                foreach ($this->currencyColumns as $columnIndex) {
                    $column = Coordinate::stringFromColumnIndex($columnIndex);
                    $sheet->getStyle("{$column}5:{$column}{$lastRow}")->getNumberFormat()
                        ->setFormatCode('"Rp" #,##0;("Rp" #,##0);"Rp" 0');
                    $sheet->getStyle("{$column}5:{$column}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }
                foreach ($this->decimalColumns as $columnIndex) {
                    $column = Coordinate::stringFromColumnIndex($columnIndex);
                    $sheet->getStyle("{$column}5:{$column}{$lastRow}")->getNumberFormat()
                        ->setFormatCode('#,##0.00');
                    $sheet->getStyle("{$column}5:{$column}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }

                foreach ($this->headings as $index => $heading) {
                    $column = Coordinate::stringFromColumnIndex($index + 1);
                    $numeric = in_array($index + 1, array_merge($this->currencyColumns, $this->decimalColumns), true) || in_array($heading, ['Margin', 'Jumlah Item'], true);
                    $maxLength = mb_strlen($heading);
                    foreach ($this->rows as $row) $maxLength = max($maxLength, mb_strlen((string) ($row[$index] ?? '')));
                    $width = $numeric ? 22 : min(38, max(14, $maxLength + 2));
                    $sheet->getColumnDimension($column)->setAutoSize(false)->setWidth($width);
                    if ($numeric) $sheet->getStyle("{$column}4:{$column}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }
                for ($row = 5; $row <= $lastRow; $row++) {
                    $lines = 1;
                    foreach ($this->headings as $index => $heading) {
                        $column = Coordinate::stringFromColumnIndex($index + 1);
                        $width = $sheet->getColumnDimension($column)->getWidth();
                        $text = (string) $sheet->getCell($column.$row)->getValue();
                        $lines = max($lines, (int) ceil(mb_strlen($text) / max(1, $width - 3)));
                        if (is_numeric($sheet->getCell($column.$row)->getValue())) $sheet->getStyle($column.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    }
                    $sheet->getRowDimension($row)->setRowHeight(max(24, $lines * 14 + 8));
                }
                $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4)
                    ->setOrientation(count($this->headings) > 7 ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT)
                    ->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(1, 4)
                    ->setPrintArea("A1:{$lastColumn}{$lastRow}");
                $sheet->getPageMargins()->setTop(.4)->setBottom(.5)->setLeft(.3)->setRight(.3);
                $sheet->getHeaderFooter()->setOddFooter('&LToko Golden&C'.$this->sheetTitle.'&RHalaman &P / &N');
            },
        ];
    }
}
