<?php

namespace App\Services;

use App\Models\Supplier;
use Illuminate\Support\Facades\Validator;

class SupplierImportPreviewService
{
    public function preview(array $data, array $products, array $resolved, bool $canReadSuppliers, callable $fail, callable $warn): array
    {
        $key = fn ($value) => mb_strtolower(trim((string)$value));
        $existing = $canReadSuppliers && ($data['Master Supplier'] || $data['Supplier Barang'])
            ? Supplier::get(['id','name','address','phone'])->keyBy('id') : collect();
        $suppliers = []; $seenCodes = []; $seenIdentities = [];
        foreach ($data['Master Supplier'] as $row) {
            $validation = Validator::make($row, [
                'kode_supplier'=>'required|string|max:64','nama_supplier'=>'nullable|string|max:150',
                'alamat'=>'nullable|string|max:2000','telepon'=>'nullable|string|max:20','id_supplier'=>'nullable|integer|min:1',
            ]);
            if ($validation->fails()) { $fail('Master Supplier',$row['_row'],implode(' ',$validation->errors()->all())); continue; }
            $code = $key($row['kode_supplier']);
            if (isset($seenCodes[$code])) { $fail('Master Supplier',$row['_row'],'Kode supplier ganda.'); continue; }
            $seenCodes[$code] = true;
            $id = !empty($row['id_supplier']) ? (int)$row['id_supplier'] : null;
            if (preg_match('/^sup-([1-9][0-9]*)$/i', $code, $match)) {
                if ($id && $id !== (int)$match[1]) { $fail('Master Supplier',$row['_row'],'Kode SUP-ID tidak sesuai id_supplier.'); continue; }
                $id = (int)$match[1];
            }
            if ($id) {
                if (!$canReadSuppliers) { $fail('Master Supplier',$row['_row'],'Referensi supplier terdaftar membutuhkan hak akses Master Supplier.'); continue; }
                $supplier = $existing[$id] ?? null;
                if (!$supplier) { $fail('Master Supplier',$row['_row'],'ID supplier tidak ditemukan di database.'); continue; }
                $identity = 'id:'.$id;
                foreach (['nama_supplier'=>'name','alamat'=>'address','telepon'=>'phone'] as $field=>$attribute) {
                    if (filled($row[$field]) && trim($row[$field]) !== trim((string)$supplier->$attribute)) {
                        $warn('Master Supplier',$row['_row'],'Data supplier berbeda dari database. Pratinjau memakai data terdaftar; tidak menimpa data lama.'); break;
                    }
                }
                $entry = ['code'=>$row['kode_supplier'],'name'=>$supplier->name,'address'=>$supplier->address,'phone'=>$supplier->phone,'id'=>$id,'status'=>'Terdaftar','identity'=>$identity];
            } else {
                if (!filled($row['nama_supplier'])) { $fail('Master Supplier',$row['_row'],'Supplier baru wajib memiliki nama_supplier.'); continue; }
                $identity = 'new:'.$key($row['nama_supplier']);
                if ($existing->contains(fn($supplier)=>$key($supplier->name)===$key($row['nama_supplier']))) $warn('Master Supplier',$row['_row'],'Nama supplier sudah ada. Isi id_supplier untuk merujuk supplier lama; nama tidak dicocokkan otomatis.');
                $entry = ['code'=>$row['kode_supplier'],'name'=>$row['nama_supplier'],'address'=>$row['alamat'],'phone'=>$row['telepon'],'id'=>null,'status'=>'Baru (uji coba)','identity'=>$identity];
            }
            if (isset($seenIdentities[$identity])) { $fail('Master Supplier',$row['_row'],'Supplier yang sama menggunakan lebih dari satu kode dalam file.'); continue; }
            $seenIdentities[$identity] = true;
            $suppliers[$code] = $entry;
        }

        $links = []; $seenLinks = [];
        foreach ($data['Supplier Barang'] as $row) {
            $validation = Validator::make($row, ['kode_barang'=>'required|string|max:64','kode_supplier'=>'required|string|max:64',
                'satuan_beli'=>'required|string|max:255','harga_beli'=>'required|numeric|decimal:0,2|min:0|max:9999999999999.99']);
            if ($validation->fails()) { $fail('Supplier Barang',$row['_row'],implode(' ',$validation->errors()->all())); continue; }
            $productCode=$key($row['kode_barang']); $supplierCode=$key($row['kode_supplier']);
            if (!isset($products[$productCode])) { $fail('Supplier Barang',$row['_row'],'Kode barang tidak ditemukan di sheet Barang.'); continue; }
            $unit=$resolved[$productCode]['units'][$key($row['satuan_beli'])] ?? null;
            if (!$unit) { $fail('Supplier Barang',$row['_row'],'Satuan beli tidak terdaftar atau konfigurasi barang belum valid.'); continue; }
            if (isset($seenCodes[$supplierCode]) && !isset($suppliers[$supplierCode])) { $fail('Supplier Barang',$row['_row'],'Data master supplier dengan kode ini belum valid.'); continue; }
            if (!isset($suppliers[$supplierCode]) && preg_match('/^sup-([1-9][0-9]*)$/i',$supplierCode,$match)) {
                if (!$canReadSuppliers) { $fail('Supplier Barang',$row['_row'],'Referensi supplier terdaftar membutuhkan hak akses Master Supplier.'); continue; }
                $supplier=$existing[(int)$match[1]] ?? null;
                if ($supplier) $suppliers[$supplierCode]=['code'=>'SUP-'.$supplier->id,'name'=>$supplier->name,'address'=>$supplier->address,'phone'=>$supplier->phone,'id'=>$supplier->id,'status'=>'Terdaftar','identity'=>'id:'.$supplier->id];
            }
            $supplier=$suppliers[$supplierCode] ?? null;
            if (!$supplier) { $fail('Supplier Barang',$row['_row'],'Kode supplier tidak ditemukan. Isi Master Supplier atau gunakan SUP-ID supplier terdaftar.'); continue; }
            $pair=json_encode([$productCode,$supplier['identity']]);
            if (isset($seenLinks[$pair])) { $fail('Supplier Barang',$row['_row'],'Hubungan barang-supplier ganda; sudah ada di baris '.$seenLinks[$pair].'.'); continue; }
            $seenLinks[$pair]=$row['_row'];
            $baseCost=round((float)$row['harga_beli']/$unit['conversion'],2);
            if (!is_finite($baseCost) || $baseCost>9999999999999.99) { $fail('Supplier Barang',$row['_row'],'Harga beli per satuan dasar melebihi batas penyimpanan.'); continue; }
            $links[]=['product_code'=>$products[$productCode]['kode_barang'],'product_name'=>$products[$productCode]['nama_barang'],
                'supplier_code'=>$supplier['code'],'supplier_name'=>$supplier['name'],'supplier_id'=>$supplier['id'],'status'=>$supplier['status'],
                'unit'=>$unit['satuan'],'price'=>(float)$row['harga_beli'],'base_price'=>$baseCost,'base'=>$resolved[$productCode]['base'],'row'=>$row['_row']];
        }
        $uniqueSuppliers = collect($suppliers)->unique('identity')->values();
        return ['suppliers'=>$uniqueSuppliers->take(200)->all(),'supplier_links'=>array_slice($links,0,200),
            'supplier_count'=>$uniqueSuppliers->count(),'supplier_link_count'=>count($links)];
    }
}
