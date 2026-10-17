<?php

namespace App\Exports;

use App\Services\ProductImportService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProductImportTemplateExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithStyles
{
    public function array(): array
    {
        return [ProductImportService::HEADINGS];
    }

    public function columnFormats(): array
    {
        return ['B' => '@', 'E' => '0.00', 'F' => '0.00', 'H' => '0.####', 'I' => '0.####'];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');
        $sheet->getStyle('B2:B5001')->getNumberFormat()->setFormatCode('@');
        $sheet->setAutoFilter('A1:J1');
        return [1 => ['font' => ['bold' => true]]];
    }
}
