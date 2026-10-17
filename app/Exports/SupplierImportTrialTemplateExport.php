<?php

namespace App\Exports;

use App\Models\Product;
use App\Models\Supplier;
use App\Services\SupplierImportTrialService;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class SupplierImportTrialTemplateExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        $suppliers=[['DEMO-SUP001','PT Pemasok Contoh','Alamat contoh','081200000001',''],['DEMO-SUP002','CV Grosir Contoh','Alamat contoh','081200000002','']];
        if (auth()->user()?->hasPermission('master.suppliers.view')) {
            foreach (Supplier::orderBy('name')->limit(4998)->get(['id','name','address','phone']) as $supplier) {
                $suppliers[]=['SUP-'.$supplier->id,$supplier->name,$supplier->address,$supplier->phone,$supplier->id];
            }
        }
        $links=[]; $references=[];
        if (auth()->user()?->hasPermission('master.products.view')) {
            foreach (Product::active()->with(['productUnits.unit','buyUnit','retailUnit'])->orderBy('name')->limit(5000)->get() as $product) {
                $names=$product->productUnits->map(fn($unit)=>$unit->unit?->name)->filter()->unique()->implode('; ');
                $references[]=[$product->id,$product->barcode ?? '',$product->name,$names ?: $product->buyUnit?->name];
                if (!$links) {
                    $purchaseUnit=$product->productUnits->firstWhere('is_default_purchase',true);
                    $unit=$purchaseUnit?->unit?->name ?? $product->buyUnit?->name;
                    if (!$unit) continue;
                    $price=(float)($purchaseUnit?->buy_price ?? $product->buy_price);
                    foreach (['DEMO-SUP001','DEMO-SUP002'] as $code) $links[]=[$product->barcode ?? '',$product->id,$code,$unit,$price];
                }
            }
        }
        return [
            new ProductImportTrialSheetExport('Master Supplier',SupplierImportTrialService::HEADINGS['Master Supplier'],$suppliers,['A'=>'@','D'=>'@','E'=>'0'],[]),
            new ProductImportTrialSheetExport('Supplier Barang',SupplierImportTrialService::HEADINGS['Supplier Barang'],$links,['A'=>'@','B'=>'0','C'=>'@','D'=>'@','E'=>'0.00'],[]),
            new ProductImportTrialSheetExport('Referensi Barang',SupplierImportTrialService::HEADINGS['Referensi Barang'],$references,['A'=>'0','B'=>'@'],[]),
        ];
    }
}
