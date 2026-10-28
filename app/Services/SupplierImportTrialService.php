<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Validator;

class SupplierImportTrialService
{
    public const HEADINGS = [
        'Master Supplier'=>['kode_supplier','nama_supplier','alamat','telepon','id_supplier'],
        'Supplier Barang'=>['barcode','id_barang','kode_supplier','satuan_beli','harga_beli'],
        'Referensi Barang'=>['id_barang','barcode','nama_barang','satuan_tersedia'],
    ];

    public function preview(array $sheets, bool $canReadProducts = false, bool $full = false, bool $matchExistingByName = false): array
    {
        $errors=[]; $warnings=[]; $errorCount=0;
        $fail=function($sheet,$row,$message) use (&$errors,&$errorCount){
            $errorCount++;
            if(count($errors)<200) $errors[]=compact('sheet','row','message');
        };
        $warn=function($sheet,$row,$message) use (&$warnings){
            if(count($warnings)<200) $warnings[]=compact('sheet','row','message');
        };
        $key=fn($value)=>mb_strtolower(trim((string)$value));
        $normalized=[];
        foreach($sheets as $name=>$rows) $normalized[$key($name)]=$rows;
        $data=['Master Supplier'=>[],'Supplier Barang'=>[]];
        foreach(self::HEADINGS as $name=>$expected){
            $rows=$normalized[$key($name)] ?? null;
            if($rows===null){
                if($name==='Master Supplier') $fail($name,1,'Sheet Master Supplier wajib ada; Supplier Barang bersifat opsional.');
                continue;
            }
            $headers=array_map($key,array_shift($rows) ?? []);
            if(count(array_unique($headers))!==count($headers) || array_diff($expected,$headers) || array_diff($headers,$expected)){
                $fail($name,1,'Judul kolom harus sesuai template, tanpa kolom ganda atau tambahan.'); continue;
            }
            if(count($rows)>5000){$fail($name,1,'Maksimal 5.000 baris data per sheet.');continue;}
            // Reference rows are never used as source data for creating or matching products.
            if($name==='Referensi Barang') continue;
            foreach($rows as $index=>$values){
                if(!array_filter($values,fn($value)=>$value!==null && trim((string)$value)!=='')) continue;
                $row=['_row'=>$index+2];
                foreach($headers as $column=>$heading) $row[$heading]=is_string($values[$column] ?? null) ? trim($values[$column]) : ($values[$column] ?? null);
                foreach(['kode_supplier','nama_supplier','alamat','telepon','barcode','satuan_beli'] as $field) if(isset($row[$field])) $row[$field]=(string)$row[$field];
                $data[$name][]=$row;
            }
        }
        foreach(array_keys($normalized) as $name) if(!in_array($name,array_map($key,array_keys(self::HEADINGS)),true)) $fail($name,1,'Sheet tidak didukung untuk import supplier. Gunakan template Import Supplier.');

        $validRows=[];
        foreach($data['Supplier Barang'] as $row){
            $validation=Validator::make($row,['barcode'=>'nullable|string|max:100','id_barang'=>'nullable|integer|min:1',
                'kode_supplier'=>'required|string|max:64','satuan_beli'=>'required|string|max:255',
                'harga_beli'=>'required|numeric|decimal:0,2|min:0|max:9999999999999.99']);
            if($validation->fails()){$fail('Supplier Barang',$row['_row'],implode(' ',$validation->errors()->all()));continue;}
            if(!filled($row['barcode']) && !filled($row['id_barang'])){$fail('Supplier Barang',$row['_row'],'Isi barcode atau id_barang dari produk terdaftar.');continue;}
            if(!$canReadProducts){$fail('Supplier Barang',$row['_row'],'Hubungan produk membutuhkan hak akses melihat Master Barang.');continue;}
            $validRows[]=$row;
        }
        $matched=collect();
        if($validRows){
            $ids=array_filter(array_column($validRows,'id_barang'),fn($value)=>filled($value));
            $barcodes=array_filter(array_column($validRows,'barcode'),fn($value)=>filled($value));
            $matched=Product::with(['productUnits.unit','buyUnit','retailUnit','suppliers'])->where(function($query) use($ids,$barcodes){
                $query->whereIn('id',$ids)->orWhereIn('barcode',$barcodes);
            })->get();
        }
        $byId=$matched->keyBy('id'); $byBarcode=$matched->groupBy('barcode');
        $products=[]; $resolved=[]; $internalRows=[];
        foreach($validRows as $row){
            if(filled($row['id_barang'])){
                $product=$byId[(int)$row['id_barang']] ?? null;
                if(!$product){$fail('Supplier Barang',$row['_row'],'ID barang tidak ditemukan.');continue;}
                if(filled($row['barcode']) && (string)$product->barcode!==$row['barcode']){$fail('Supplier Barang',$row['_row'],'Barcode dan ID barang tidak merujuk produk yang sama.');continue;}
            }else{
                $candidates=$byBarcode[$row['barcode']] ?? collect();
                if($candidates->count()!==1){$fail('Supplier Barang',$row['_row'],$candidates->isEmpty() ? 'Barcode tidak ditemukan; barang harus sudah terdaftar.' : 'Barcode tidak unik. Isi id_barang untuk menentukan produk.');continue;}
                $product=$candidates->first();
            }
            if(!$product->is_active){$fail('Supplier Barang',$row['_row'],'Barang sudah tidak aktif.');continue;}
            $code=(string)$product->id;
            $units=[];
            foreach($product->productUnits as $unit){
                if(!$unit->unit || (float)$unit->conversion_to_base<=0) continue;
                $units[$key($unit->unit->name)]=['satuan'=>$unit->unit->name,'conversion'=>(float)$unit->conversion_to_base];
            }
            $base=$product->productUnits->firstWhere('is_base_unit',true)?->unit?->name;
            if(!$units){
                $base=$product->can_retail ? $product->retailUnit?->name : $product->buyUnit?->name;
                if($base) $units[$key($base)]=['satuan'=>$base,'conversion'=>1.0];
                if($product->buyUnit && $product->can_retail && (float)$product->conversion_factor>0) $units[$key($product->buyUnit->name)]=['satuan'=>$product->buyUnit->name,'conversion'=>(float)$product->conversion_factor];
            }
            if(!$base){$fail('Supplier Barang',$row['_row'],'Satuan dasar produk belum valid. Perbaiki di Master Barang.');continue;}
            $products[$code]=['kode_barang'=>$code,'nama_barang'=>$product->name];
            $resolved[$code]=['base'=>$base,'units'=>$units];
            $internalRows[]=array_merge($row,['kode_barang'=>$code]);
        }
        $data['Supplier Barang']=$internalRows;
        $result=app(SupplierImportPreviewService::class)->preview($data,$products,$resolved,true,$fail,$warn,$full,$matchExistingByName);
        foreach($result['supplier_links'] as &$link){
            $product=$byId[(int)$link['product_code']];
            $existing=$link['supplier_id'] ? $product->suppliers->firstWhere('id',$link['supplier_id']) : null;
            $link['barcode']=$product->barcode;
            $link['relationship_status']=$existing ? 'Sudah terhubung (tidak diubah)' : 'Hubungan baru (uji coba)';
            $link['existing_price']=$existing ? (float)$existing->pivot->buy_price : null;
            $link['existing_unit']=$product->productUnits->firstWhere('is_default_purchase',true)?->unit?->name ?? $product->buyUnit?->name;
            $purchaseUnit = $product->productUnits->firstWhere('is_default_purchase',true);
            $purchaseConversion = $purchaseUnit?->conversion_to_base
                ?? ($product->can_retail ? $product->conversion_factor : 1);
            $inputConversion = $resolved[(string) $product->id]['units'][$key($link['unit'])]['conversion'];
            $link['input_conversion'] = (string) $inputConversion;
            $link['purchase_conversion'] = (string) $purchaseConversion;
            $link['purchase_price'] = (string) \Brick\Math\BigDecimal::of((string) $link['price'])
                ->multipliedBy((string) $purchaseConversion)->dividedBy((string) $inputConversion, 2, \Brick\Math\RoundingMode::HalfUp);
            if (!$link['existing_unit'] || (float) $purchaseConversion <= 0 || (float) $link['purchase_price'] > 9999999999999.99) {
                $fail('Supplier Barang',$link['row'],'Satuan atau harga pembelian default barang tidak valid. Perbaiki Master Barang terlebih dahulu.');
            }
        }
        unset($link);
        if(!$data['Master Supplier'] && !$internalRows && !$errorCount) $fail('Master Supplier',2,'Isi minimal satu supplier atau satu hubungan barang-supplier.');
        return array_merge($result,compact('errors','warnings'),['error_count'=>$errorCount]);
    }
}
