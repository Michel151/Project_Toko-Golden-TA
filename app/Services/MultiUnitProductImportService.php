<?php

namespace App\Services;

use App\Models\{Category,Location,Product,ProductImportRun,ProductLocation,ProductUnit,Unit};
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MultiUnitProductImportService
{
    public function prepare(array $sheets): array
    {
        $plan=app(ProductImportTrialService::class)->preview($sheets,auth()->user()?->hasPermission('master.suppliers.view') ?? false,true);
        $issues=[]; $names=[]; $normalize=fn($value)=>mb_strtolower(trim((string)$value));
        $sheetNames=array_map($normalize,array_keys($sheets)); sort($sheetNames);
        if($sheetNames!==['barang','satuan','stok awal']) $issues[]=['sheet'=>'File','row'=>1,'message'=>'Penyimpanan hanya menerima template tiga sheet barang. Supplier tetap diimpor melalui menu Master Supplier.'];
        foreach($plan['products'] as $product){
            $name=$normalize($product['nama_barang']);
            if(isset($names[$name])) $issues[]=['sheet'=>'Barang','row'=>$product['_row'],'message'=>'Nama barang ganda dalam file. Gunakan nama yang berbeda untuk setiap produk.'];
            $names[$name]=true;
            $existing=Product::where(function($query) use($product,$name){
                $query->whereRaw('LOWER(name) = ?',[$name]);
                if(filled($product['barcode'])) $query->orWhere('barcode',$product['barcode']);
            })->exists();
            if($existing) $issues[]=['sheet'=>'Barang','row'=>$product['_row'],'message'=>'Barang/barcode sudah terdaftar. Impor multi satuan hanya membuat barang baru; data lama tidak ditimpa.'];
            foreach($product['units'] as $unit){
                if($normalize($unit['satuan'])===$normalize($product['satuan_beli']) && $unit['conversion']>999999.9999) $issues[]=['sheet'=>'Satuan','row'=>$unit['_row'],'message'=>'Konversi satuan beli melebihi batas 999999,9999 pada produk.'];
            }
        }
        $plan['error_count']+=count($issues);
        $plan['errors']=array_slice(array_merge($plan['errors'],$issues),0,200);
        return $plan;
    }

    public function stage(array $sheets, int $userId): ProductImportRun
    {
        ProductImportRun::where('user_id',$userId)->where('status','pending')->where('expires_at','<',now('Asia/Jakarta'))->delete();
        return ProductImportRun::create(['token'=>(string)Str::uuid(),'user_id'=>$userId,'payload'=>$sheets,
            'expires_at'=>now('Asia/Jakarta')->addHour()]);
    }

    public function commit(string $token, int $userId): array
    {
        // Serialize separate uploads as well as double-clicks on the same confirmation.
        return Cache::store('file')->lock('multi-unit-product-import',900)->block(10,function() use($token,$userId){
            return DB::transaction(function() use($token,$userId){
                $run=ProductImportRun::where('token',$token)->where('user_id',$userId)->lockForUpdate()->firstOrFail();
                if($run->status==='completed') return $run->result;
                if($run->expires_at->isPast() || !$run->payload) throw ValidationException::withMessages(['file'=>'Pratinjau sudah kedaluwarsa. Unggah ulang file sebelum menyimpan.']);
                $plan=$this->prepare($run->payload);
                if($plan['error_count']) throw ValidationException::withMessages(['file'=>array_map(fn($issue)=>$issue['sheet'].' baris '.$issue['row'].': '.$issue['message'],$plan['errors'])]);
                $result=['created'=>0,'units'=>0,'stocks'=>0,'batches'=>0,'product_ids'=>[]];
                $products=[]; $locations=[]; $named=[];
                foreach($plan['products'] as $row){
                    $unitIds=[];
                    foreach($row['units'] as $unit) $unitIds[$this->key($unit['satuan'])]=$this->named(Unit::class,$unit['satuan'],$named)->id;
                    $definitions=collect($row['units'])->keyBy(fn($unit)=>$this->key($unit['satuan']));
                    $buy=$definitions[$this->key($row['satuan_beli'])]; $base=$definitions[$this->key($row['base'])];
                    $product=Product::create([
                        'name'=>$row['nama_barang'],'barcode'=>filled($row['barcode']) ? $row['barcode'] : null,
                        'category_id'=>$this->named(Category::class,$row['kategori'],$named)->id,
                        'buy_unit_id'=>$unitIds[$this->key($row['satuan_beli'])],'buy_price'=>$buy['harga_beli'],
                        'sell_price_wholesale'=>$buy['harga_jual'],'can_retail'=>count($row['units'])>1,
                        'retail_unit_id'=>$unitIds[$this->key($row['base'])],'conversion_factor'=>$buy['conversion'],
                        'sell_price_retail'=>$base['harga_jual'],'min_stock_total'=>$row['stok_minimum'] ?: 0,
                        'is_active'=>true,'is_expirable'=>$row['kedaluwarsa']==='ya',
                    ]);
                    if(!$product->barcode) $product->updateQuietly(['barcode'=>'TG'.str_pad((string)$product->id,10,'0',STR_PAD_LEFT)]);
                    $createdUnits=[];
                    foreach($row['units'] as $unit){
                        $key=$this->key($unit['satuan']);
                        $createdUnits[$key]=ProductUnit::create(['product_id'=>$product->id,'unit_id'=>$unitIds[$key],
                            'contains_quantity'=>$unit['isi'],'conversion_to_base'=>$unit['conversion'],
                            'buy_price'=>$unit['harga_beli'],'sell_price'=>$unit['harga_jual'],
                            'is_base_unit'=>$key===$this->key($row['base']),
                            'is_default_purchase'=>$key===$this->key($row['satuan_beli']),
                            'is_default_sale'=>$key===$this->key($row['satuan_jual'])]);
                    }
                    foreach($row['units'] as $unit){
                        $key=$this->key($unit['satuan']);
                        if($key!==$this->key($row['base'])) $createdUnits[$key]->update(['contains_unit_id'=>$createdUnits[$this->key($unit['satuan_isi'])]->id]);
                    }
                    $products[$this->key($row['kode_barang'])]=$product;
                    $result['created']++; $result['units']+=count($createdUnits); $result['product_ids'][]=$product->id;
                }
                foreach($plan['stocks'] as $row){
                    $product=$products[$this->key($row['code'])];
                    $location=$this->named(Location::class,$row['location'],$named);
                    ProductLocation::firstOrCreate(['product_id'=>$product->id,'location_id'=>$location->id],['min_stock'=>0]);
                    app(StockService::class)->initializeImportedStock($product,$location,(float)$row['quantity'],$run,$row['expiry'],$product->is_expirable ? $row['batch'] : null);
                    $locations[$product->id.':'.$location->id]=true;
                    if($product->is_expirable && $row['quantity']>0) $result['batches']++;
                }
                $result['stocks']=count($locations);
                $run->update(['status'=>'completed','payload'=>null,'result'=>$result]);
                return $result;
            },3);
        });
    }

    private function key(string $name): string { return mb_strtolower(trim($name)); }

    private function named(string $class, string $name, array &$cache)
    {
        $key=$class.':'.$this->key($name);
        if(!isset($cache[$key])){
            $matches=$class::whereRaw('LOWER(name) = ?',[$this->key($name)])->lockForUpdate()->get();
            if($matches->count()>1) throw ValidationException::withMessages(['file'=>'Nama referensi tidak unik: '.$name.'. Rapikan data master terlebih dahulu.']);
            $cache[$key]=$matches->first() ?? $class::create(['name'=>$name]);
        }
        return $cache[$key];
    }
}
