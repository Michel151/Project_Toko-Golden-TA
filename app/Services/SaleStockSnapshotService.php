<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Validation\ValidationException;

class SaleStockSnapshotService
{
    // The caller holds the sale lock and transaction throughout recovery and restoration.
    public function recoverLegacySources(Sale $sale): void
    {
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
            $remaining=round(-(float) $movements->sum('quantity')-array_sum(array_map(
                fn ($row)=>(float) ($row['source']->base_quantity ?? 0),$rows)),4);
            $unitKeys=array_unique(array_map(fn ($row)=>$row['item']->product_unit_id ?? 'legacy-'.$row['item']->sale_type,$missing));
            $batchIds=array_unique(array_map(fn ($row)=>$row['source']->stock_batch_id,$missing));
            $sourceQuantity=array_sum(array_map(fn ($row)=>(float) $row['source']->quantity,$missing));
            if ($movements->isEmpty() || $remaining<=0 || count($unitKeys)!==1 || count($batchIds)>1 || $sourceQuantity<=0) {
                throw ValidationException::withMessages(['sale'=>'Nota lama '.$sale->sale_number.' belum memiliki jumlah stok awal yang dapat dipastikan. Periksa riwayat mutasi sebelum membatalkan atau mengedit nota; stok belum diubah.']);
            }
            // A single historical unit allows its original factor to be inferred from recorded stock movements.
            $unallocated=$remaining;
            $missing=array_values($missing);
            foreach ($missing as $index=>$row) {
                $quantity=$index===count($missing)-1 ? $unallocated
                    : min($unallocated,round($remaining*(float) $row['source']->quantity/$sourceQuantity,4));
                $row['source']->update(['base_quantity'=>$quantity]);
                $unallocated=round($unallocated-$quantity,4);
            }
        }
    }
}
