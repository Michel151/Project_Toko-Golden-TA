<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class ProductImportTrialService
{
    public const HEADINGS = [
        'Barang' => ['kode_barang','nama_barang','barcode','kategori','satuan_beli','satuan_jual','stok_minimum','kedaluwarsa'],
        'Satuan' => ['kode_barang','satuan','isi','satuan_isi','harga_beli','harga_jual'],
        'Stok Awal' => ['kode_barang','lokasi','jumlah','satuan','tanggal_kedaluwarsa'],
        'Supplier Barang' => ['kode_barang','kode_supplier','satuan_beli','harga_beli'],
        'Master Supplier' => ['kode_supplier','nama_supplier','alamat','telepon','id_supplier'],
    ];

    public function preview(array $sheets, bool $canReadSuppliers = false, bool $full = false): array
    {
        $errors = []; $warnings = []; $errorCount = 0;
        $fail = function ($sheet, $row, $message) use (&$errors, &$errorCount) {
            $errorCount++;
            if (count($errors) < 200) $errors[] = compact('sheet','row','message');
        };
        $warn = function ($sheet, $row, $message) use (&$warnings) {
            if (count($warnings) < 200) $warnings[] = compact('sheet','row','message');
        };
        $key = fn ($value) => mb_strtolower(trim((string) $value));
        $normalized = [];
        foreach ($sheets as $name => $rows) $normalized[$key($name)] = $rows;
        $data = [];
        foreach (self::HEADINGS as $name => $expected) {
            $rows = $normalized[$key($name)] ?? null;
            $data[$name] = [];
            if ($rows === null) {
                if (!in_array($name,['Supplier Barang','Master Supplier'],true)) $fail($name,1,'Sheet wajib tidak ditemukan.');
                continue;
            }
            // Excel can retain an oversized used range after columns are cleared.
            // Ignore trailing columns only when both their heading and all data are empty.
            $width = max(array_map('count', $rows ?: [[]]));
            while ($width > 0) {
                foreach ($rows as $values) {
                    if ($key($values[$width - 1] ?? null) !== '') break 2;
                }
                $width--;
            }
            $rows = array_map(fn ($values) => array_slice($values, 0, $width), $rows);
            $headers = array_map($key, array_shift($rows) ?? []);
            // Keep the optional code column readable in previously downloaded templates.
            $allowed = $name === 'Stok Awal' ? [...$expected, 'kode_batch'] : $expected;
            if (count(array_unique($headers)) !== count($headers) || array_diff($expected,$headers) || array_diff($headers,$allowed)) {
                $fail($name,1,'Judul kolom harus sesuai template, tanpa kolom ganda atau tambahan.'); continue;
            }
            if (count($rows) > 5000) { $fail($name,1,'Maksimal 5.000 baris data per sheet.'); continue; }
            foreach ($rows as $index => $values) {
                if (!array_filter($values,fn($value)=>$value!==null && trim((string)$value)!=='')) continue;
                $row = ['_row'=>$index+2];
                if ($name === 'Stok Awal') $row['kode_batch'] = null;
                foreach ($headers as $column => $header) $row[$header] = is_string($values[$column] ?? null) ? trim($values[$column]) : ($values[$column] ?? null);
                foreach (['kode_barang','nama_barang','barcode','kategori','satuan_beli','satuan_jual','kedaluwarsa','satuan','satuan_isi','lokasi','kode_batch','kode_supplier','nama_supplier','alamat','telepon'] as $field) {
                    if (isset($row[$field])) $row[$field]=(string)$row[$field];
                }
                $data[$name][] = $row;
            }
        }
        foreach (array_keys($normalized) as $name) {
            if (!in_array($name,array_map($key,array_keys(self::HEADINGS)),true)) $fail($name,1,'Sheet tambahan tidak didukung.');
        }

        $products = []; $barcodes = [];
        foreach ($data['Barang'] as $row) {
            $validation = Validator::make($row,[
                'kode_barang'=>'required|string|max:64','nama_barang'=>'required|string|max:200','barcode'=>'nullable|string|max:100',
                'kategori'=>'required|string|max:255','satuan_beli'=>'required|string|max:255','satuan_jual'=>'required|string|max:255',
                'stok_minimum'=>'nullable|numeric|decimal:0,4|min:0|max:99999999999.9999','kedaluwarsa'=>'required|in:ya,tidak',
            ]);
            if ($validation->fails()) { $fail('Barang',$row['_row'],implode(' ',$validation->errors()->all())); continue; }
            $code = $key($row['kode_barang']);
            if (isset($products[$code])) { $fail('Barang',$row['_row'],'Kode barang ganda, sudah ada di baris '.$products[$code]['_row'].'.'); continue; }
            if (!empty($row['barcode']) && isset($barcodes[$row['barcode']])) { $fail('Barang',$row['_row'],'Barcode digunakan oleh kode barang lain.'); continue; }
            if (!empty($row['barcode'])) $barcodes[$row['barcode']] = true;
            $products[$code] = $row;
        }
        if (!$products) $fail('Barang',2,'Isi minimal satu barang untuk pratinjau.');

        $units = []; $invalidUnits = [];
        foreach ($data['Satuan'] as $row) {
            $code = $key($row['kode_barang']);
            if (!isset($products[$code])) { $fail('Satuan',$row['_row'],'Kode barang tidak ditemukan di sheet Barang.'); continue; }
            $validation = Validator::make($row,[
                'satuan'=>'required|string|max:255','satuan_isi'=>'required|string|max:255',
                'isi'=>'required|numeric|decimal:0,4|min:0.0001|max:99999999999.9999',
                'harga_beli'=>'required|numeric|decimal:0,2|min:0|max:9999999999999.99',
                'harga_jual'=>'required|numeric|decimal:0,2|min:0|max:9999999999999.99',
            ]);
            if ($validation->fails()) { $invalidUnits[$code]=true; $fail('Satuan',$row['_row'],implode(' ',$validation->errors()->all())); continue; }
            $name = $key($row['satuan']);
            if (isset($units[$code][$name])) { $invalidUnits[$code]=true; $fail('Satuan',$row['_row'],'Satuan ganda untuk barang yang sama.'); continue; }
            $units[$code][$name] = $row;
        }

        $resolved = []; $productPreview = [];
        foreach ($products as $code => $product) {
            $definitions = $units[$code] ?? [];
            if (isset($invalidUnits[$code])) continue;
            $bases = array_filter($definitions,fn($unit)=>$key($unit['satuan'])===$key($unit['satuan_isi']) && (float)$unit['isi']===1.0);
            if (count($bases)!==1 || count($definitions)>40) { $fail('Satuan', $definitions ? reset($definitions)['_row'] : 1, 'Barang '.$product['kode_barang'].' membutuhkan tepat satu satuan dasar (isi 1, satuan_isi sama) dan maksimal 40 satuan.'); continue; }
            $base = array_key_first($bases); $conversions = []; $valid = true;
            foreach ($definitions as $name => $unit) {
                $visited = []; $current = $name; $conversion = 1.0;
                while ($current!==$base) {
                    if (isset($visited[$current])) { $fail('Satuan',$unit['_row'],'Rantai satuan berputar untuk '.$unit['satuan'].'.'); $valid=false; break; }
                    if (!isset($definitions[$current])) { $fail('Satuan',$unit['_row'],'Satuan isi tidak ditemukan untuk '.$unit['satuan'].'.'); $valid=false; break; }
                    $visited[$current]=true;
                    $conversion *= (float)$definitions[$current]['isi'];
                    if (!is_finite($conversion) || $conversion>99999999999.9999) { $fail('Satuan',$unit['_row'],'Hasil konversi terlalu besar.'); $valid=false; break; }
                    $current=$key($definitions[$current]['satuan_isi']);
                }
                if ($current!==$base) continue;
                if ($conversion<0.0001 || abs($conversion-round($conversion,4))>0.0000001) { $fail('Satuan',$unit['_row'],'Konversi harus dapat disimpan dengan maksimal 4 angka desimal.'); $valid=false; continue; }
                $conversions[$name] = array_merge($unit,['conversion'=>round($conversion,4)]);
            }
            if (!isset($definitions[$key($product['satuan_beli'])]) || !isset($definitions[$key($product['satuan_jual'])])) { $fail('Barang',$product['_row'],'Satuan beli/jual tidak ditemukan di sheet Satuan.'); $valid=false; }
            if (!$valid) continue;
            $resolved[$code] = ['base'=>$definitions[$base]['satuan'],'units'=>$conversions];
            $productPreview[$code] = array_merge($product,['base'=>$definitions[$base]['satuan'],'units'=>array_values($conversions)]);
        }

        $stockGroups = []; $batchDates = []; $locationTotals = [];
        $today = now('Asia/Jakarta')->toDateString();
        foreach ($data['Stok Awal'] as $row) {
            $code=$key($row['kode_barang']);
            if (!isset($products[$code])) { $fail('Stok Awal',$row['_row'],'Kode barang tidak ditemukan di sheet Barang.'); continue; }
            if (!isset($resolved[$code])) { $fail('Stok Awal',$row['_row'],'Konfigurasi satuan barang belum valid.'); continue; }
            $validation=Validator::make($row,['lokasi'=>'required|string|max:255','satuan'=>'required|string|max:255','jumlah'=>'required|numeric|decimal:0,4|min:0|max:99999999999.9999','kode_batch'=>'nullable|string|max:100']);
            if ($validation->fails()) { $fail('Stok Awal',$row['_row'],implode(' ',$validation->errors()->all())); continue; }
            $unit=$resolved[$code]['units'][$key($row['satuan'])] ?? null;
            if (!$unit) { $fail('Stok Awal',$row['_row'],'Satuan stok tidak terdaftar untuk barang ini.'); continue; }
            $expirable=$products[$code]['kedaluwarsa']==='ya'; $expiry=null;
            $date=$row['tanggal_kedaluwarsa'];
            if ($date!==null && $date!=='') {
                try {
                    if (is_numeric($date)) $date=Date::excelToDateTimeObject((float)$date,new \DateTimeZone('Asia/Jakarta'))->format('Y-m-d');
                    if (!is_string($date) || !Carbon::hasFormat($date,'Y-m-d')) throw new \InvalidArgumentException;
                    $expiry=Carbon::createFromFormat('!Y-m-d',$date,'Asia/Jakarta')->toDateString();
                    if ($expiry!==$date) throw new \InvalidArgumentException;
                } catch (\Throwable) { $fail('Stok Awal',$row['_row'],'Tanggal kedaluwarsa harus tanggal Excel atau YYYY-MM-DD yang valid.'); continue; }
            }
            $batchCode=filled($row['kode_batch']) ? $row['kode_batch'] : null;
            if ($expirable && !$expiry) { $fail('Stok Awal',$row['_row'],'Barang dengan kedaluwarsa ya wajib memiliki tanggal_kedaluwarsa.'); continue; }
            if (!$expirable && ($batchCode!==null || $expiry)) { $fail('Stok Awal',$row['_row'],'Rincian kedaluwarsa diisi tetapi kedaluwarsa barang adalah tidak.'); continue; }
            $quantity=round((float)$row['jumlah']*$unit['conversion'],4);
            if (!is_finite($quantity) || $quantity>99999999999.9999 || abs((float)$row['jumlah']*$unit['conversion']-$quantity)>0.0000001) { $fail('Stok Awal',$row['_row'],'Hasil stok terlalu besar atau melebihi 4 angka desimal.'); continue; }
            $batchKey=json_encode([$code,$key($row['kode_batch'] ?? '')]);
            if ($expirable && $batchCode!==null && isset($batchDates[$batchKey]) && $batchDates[$batchKey]!==$expiry) { $fail('Stok Awal',$row['_row'],'Kode batch yang sama pada file lama memiliki tanggal kedaluwarsa berbeda.'); continue; }
            if ($expirable && $batchCode!==null) $batchDates[$batchKey]=$expiry;
            $locationKey=json_encode([$code,$key($row['lokasi'])]);
            $groupKey=json_encode([$code,$key($row['lokasi']),$key($row['kode_batch'] ?? ''),$expiry]);
            if (($locationTotals[$locationKey] ?? 0)+$quantity>99999999999.9999) { $fail('Stok Awal',$row['_row'],'Total stok lokasi melebihi batas penyimpanan.'); continue; }
            $locationTotals[$locationKey]=($locationTotals[$locationKey] ?? 0)+$quantity;
            if (!isset($stockGroups[$groupKey])) $stockGroups[$groupKey]=['code'=>$products[$code]['kode_barang'],'name'=>$products[$code]['nama_barang'],'location'=>$row['lokasi'],'batch'=>$batchCode,'expiry'=>$expiry,'base'=>$resolved[$code]['base'],'quantity'=>0,'sellable'=>0,'parts'=>[]];
            $stockGroups[$groupKey]['quantity']=round($stockGroups[$groupKey]['quantity']+$quantity,4);
            $expired=$expiry && $expiry<$today;
            if (!$expired) $stockGroups[$groupKey]['sellable']=round($stockGroups[$groupKey]['sellable']+$quantity,4);
            $stockGroups[$groupKey]['parts'][]=['row'=>$row['_row'],'quantity'=>(float)$row['jumlah'],'unit'=>$unit['satuan']];
            if ($expired && $quantity>0) $warn('Stok Awal',$row['_row'],'Stok sudah kedaluwarsa: termasuk stok fisik, tidak termasuk stok layak jual.');
        }
        $supplierData = app(SupplierImportPreviewService::class)->preview($data, $products, $resolved, $canReadSuppliers, $fail, $warn);
        return array_merge($supplierData, ['products'=>$full ? array_values($productPreview) : array_slice(array_values($productPreview),0,100),'stocks'=>$full ? array_values($stockGroups) : array_slice(array_values($stockGroups),0,200),
            'product_count'=>count($productPreview),'stock_count'=>count($stockGroups),'error_count'=>$errorCount,'errors'=>$errors,'warnings'=>$warnings]);
    }

}
