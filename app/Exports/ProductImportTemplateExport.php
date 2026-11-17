<?php

namespace App\Exports;

use App\Services\ProductImportPreviewService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ProductImportTemplateExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        $expiry=now('Asia/Jakarta')->addMonths(6)->toDateString();
        return [
            new ProductImportSheetExport('Barang',ProductImportPreviewService::HEADINGS['Barang'],[
                ['DEMO001','Rokok Contoh','0012345678901','Rokok','Dus','Bks',10,'tidak'],
                ['DEMO002','Biskuit Contoh','0012345678902','Makanan','Dus','Pcs',24,'ya'],
            ],['A'=>'@','C'=>'@','G'=>'0.####'],['E'=>'"Dus,Bal,Slop,Bks,Pcs"','F'=>'"Dus,Bal,Slop,Bks,Pcs"','H'=>'"ya,tidak"']),
            new ProductImportSheetExport('Satuan',ProductImportPreviewService::HEADINGS['Satuan'],[
                ['DEMO001','Dus',5,'Bal',400000,450000],
                ['DEMO001','Bal',10,'Slop',80000,90000],
                ['DEMO001','Slop',10,'Bks',8000,9000],
                ['DEMO001','Bks',1,'Bks',800,1000],
                ['DEMO002','Dus',24,'Pcs',48000,60000],
                ['DEMO002','Pcs',1,'Pcs',2000,3000],
            ],['A'=>'@','C'=>'0.####','E'=>'0.00','F'=>'0.00'],['B'=>'"Dus,Bal,Slop,Bks,Pcs"','D'=>'"Dus,Bal,Slop,Bks,Pcs"']),
            new ProductImportSheetExport('Stok Awal',ProductImportPreviewService::HEADINGS['Stok Awal'],[
                ['DEMO001','Toko',2,'Dus',''],
                ['DEMO001','Toko',3,'Bks',''],
                ['DEMO001','Gudang 1',20,'Dus',''],
                ['DEMO001','Gudang 2',25,'Bal',''],
                ['DEMO002','Toko',2,'Dus',$expiry],
                ['DEMO002','Toko',3,'Pcs',$expiry],
            ],['A'=>'@','C'=>'0.####','E'=>'@'],['D'=>'"Dus,Bal,Slop,Bks,Pcs"']),
        ];
    }
}
