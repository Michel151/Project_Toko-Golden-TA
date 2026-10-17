<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

class TrialWorkbookReader
{
    public function read(UploadedFile $file, int $minSheets, int $maxSheets): array
    {
        $path = $file->getRealPath();
        $book = null;
        try {
            $zip = new \ZipArchive;
            if ($zip->open($path) !== true) throw new \RuntimeException('Invalid Excel archive');
            try {
                $expandedSize = 0;
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $expandedSize += $zip->statIndex($index)['size'];
                    if ($expandedSize > 30 * 1024 * 1024) throw ValidationException::withMessages(['file' => 'Isi file terlalu besar setelah dibuka. Pisahkan data ke beberapa file.']);
                }
            } finally {
                $zip->close();
            }
            $reader = new Xlsx;
            $reader->setReadDataOnly(true);
            $reader->setReadFilter(new class implements IReadFilter {
                public function readCell($columnAddress, $row, $worksheetName = ''): bool
                {
                    return $row <= 5002 && strlen($columnAddress) === 1 && $columnAddress <= 'I';
                }
            });
            $info = $reader->listWorksheetInfo($path);
            if (count($info) < $minSheets || count($info) > $maxSheets || collect($info)->contains(fn ($sheet) => $sheet['totalRows'] > 5001 || $sheet['totalColumns'] > 8)) {
                throw ValidationException::withMessages(['file' => 'Gunakan sheet sesuai template, maksimal 5.000 baris data per sheet dan 8 kolom.']);
            }
            $book = $reader->load($path);
            $sheets = [];
            foreach ($book->getAllSheets() as $sheet) {
                foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                    if (in_array($sheet->getCell($coordinate)->getDataType(), [DataType::TYPE_FORMULA, DataType::TYPE_ERROR], true)) {
                        throw ValidationException::withMessages(['file' => "Sheet {$sheet->getTitle()}, sel {$coordinate}: gunakan nilai biasa, bukan rumus atau error Excel."]);
                    }
                }
                $sheets[$sheet->getTitle()] = $sheet->toArray(null, false, false, false);
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['file' => 'File tidak dapat dibaca. Gunakan template .xlsx tanpa proteksi sandi.']);
        } finally {
            $book?->disconnectWorksheets();
        }
        return $sheets;
    }
}
