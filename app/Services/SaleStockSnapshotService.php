<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Validation\ValidationException;

class SaleStockSnapshotService
{
    // Memulihkan jumlah dasar sumber lama dari riwayat mutasi sebelum pengembalian stok atau perhitungan HPP.
    public function recoverLegacySources(Sale $sale): void
    {
        // Memisahkan sumber berdasarkan produk dan lokasi agar jumlah historis tidak tercampur.
        $groups=[];
        foreach ($sale->items as $item) {
            foreach ($item->sources as $source) {
                $groups[$item->product_id.':'.$source->location_id][]=['item'=>$item,'source'=>$source];
            }
        }
        foreach ($groups as $rows) {
            $missing=array_filter($rows,fn ($row)=>$row['source']->base_quantity===null);
            if (!$missing) continue;
            $first=reset($rows);
            $movements=StockMovement::where('reference_type',$sale->getMorphClass())->where('reference_id',$sale->id)
                ->where('product_id',$first['item']->product_id)->where('location_id',$first['source']->location_id)
                ->whereIn('movement_type',['sale','sale_edit_restore'])->get();
            // Mengurangi jumlah dasar yang sudah diketahui dari total stok keluar bersih setelah edit.
            $remaining=round(-(float) $movements->sum('quantity')-array_sum(array_map(
                fn ($row)=>(float) ($row['source']->base_quantity ?? 0),$rows)),4);
            $unitKeys=array_unique(array_map(fn ($row)=>$row['item']->product_unit_id ?? 'legacy-'.$row['item']->sale_type,$missing));
            $batchIds=array_unique(array_map(fn ($row)=>$row['source']->stock_batch_id,$missing));
            $sourceQuantity=array_sum(array_map(fn ($row)=>(float) $row['source']->quantity,$missing));
            // Menolak pemulihan jika riwayat, kesamaan satuan, atau pembagian batch belum dapat dipastikan.
            if ($movements->isEmpty() || $remaining<=0 || count($unitKeys)!==1 || count($batchIds)>1 || $sourceQuantity<=0) {
                throw ValidationException::withMessages(['sale'=>'Nota lama '.$sale->sale_number.' belum memiliki jumlah stok awal yang dapat dipastikan. Periksa riwayat mutasi sebelum membatalkan atau mengedit nota; stok belum diubah.']);
            }
            // Sumber dengan satuan yang sama dapat dibagi menurut jumlah jual menggunakan total dasar historis.
            $unallocated=$remaining;
            $missing=array_values($missing);
            foreach ($missing as $index=>$row) {
                // Membagi menurut proporsi jumlah jual; sumber terakhir menerima sisa pembulatan.
                $quantity=$index===count($missing)-1 ? $unallocated
                    : min($unallocated,round($remaining*(float) $row['source']->quantity/$sourceQuantity,4));
                $row['source']->update(['base_quantity'=>$quantity]);
                $unallocated=round($unallocated-$quantity,4);
            }
        }
    }
}
