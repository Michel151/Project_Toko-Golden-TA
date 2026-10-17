<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\{FromArray, ShouldAutoSize, WithColumnFormatting, WithCustomValueBinder, WithStyles, WithTitle};
use PhpOffice\PhpSpreadsheet\Cell\{Cell, DataType, DataValidation, DefaultValueBinder};
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProductImportTrialSheetExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithCustomValueBinder, WithStyles, WithTitle
{
    public function __construct(private string $name, private array $headers, private array $rows, private array $formats, private array $dropdowns) {}

    public function title(): string { return $this->name; }
    public function array(): array { return [$this->headers,...$this->rows]; }
    public function columnFormats(): array { return $this->formats; }

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value,DataType::TYPE_STRING);
            return true;
        }
        return (new DefaultValueBinder)->bindValue($cell,$value);
    }

    public function styles(Worksheet $sheet): array
    {
        $last=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($this->headers));
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$last}".(count($this->rows)+1));
        $sheet->getRowDimension(1)->setRowHeight(26);
        foreach ($this->formats as $column=>$format) $sheet->getStyle("{$column}:{$column}")->getNumberFormat()->setFormatCode($format);
        foreach ($this->dropdowns as $column=>$formula) {
            $sheet->getCell($column.'2')->getDataValidation()->setType(DataValidation::TYPE_LIST)
                ->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(false)
                ->setFormula1($formula)->setSqref("{$column}2:{$column}5001");
        }
        return [1=>['font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF']], 'fill'=>['fillType'=>'solid','startColor'=>['rgb'=>'243228']]]];
    }
}
