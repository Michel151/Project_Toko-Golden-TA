<?php

namespace App\Services;

use App\Models\{Stock, StockBatch, StockMovement, StockOpname, StockOpnameItem};
use Illuminate\Validation\ValidationException;

class OpnameExpiryService
{
    public function capture(StockOpnameItem $item, int $locationId): void
    {
        if ($item->tracks_expiry!==null) return;
        $product=$item->product;
        $item->update(['tracks_expiry'=>$product->is_expirable]);
        if (!$product->is_expirable) return;
        $stock=Stock::where('product_id',$item->product_id)->where('location_id',$locationId)->lockForUpdate()->first();
        if (abs((float) ($stock?->quantity ?? 0)-(float) $item->system_quantity)>.00001
            || abs((float) $item->physical_quantity-(float) $item->system_quantity)>.00001) {
            $this->invalid('Draf lama tidak sesuai dengan stok/rincian saat ini. Buat draf opname baru untuk menghitung per kedaluwarsa.');
        }
        $batches=StockBatch::where('product_id',$item->product_id)->where('location_id',$locationId)
            ->where('quantity_remaining','>',0)->lockForUpdate()->get();
        $gap=round((float) $item->system_quantity-(float) $batches->sum('quantity_remaining'),4);
        if ($gap<-.00001) $this->invalid('Saldo stok '.$product->name.' tidak sesuai dengan rincian kedaluwarsa. Periksa kartu stok sebelum membuat opname.');
        $groups=$batches->groupBy(fn ($batch)=>$batch->expired_date?->toDateString() ?? '');
        foreach ($groups as $date=>$rows) {
            $quantity=round((float) $rows->sum('quantity_remaining')+($date==='' ? max(0,$gap) : 0),4);
            $item->expiryCounts()->create(['expired_date'=>$date ?: null,'system_quantity'=>$quantity,
                'physical_quantity'=>$quantity,'batch_snapshot'=>$rows->mapWithKeys(fn ($batch)=>[$batch->id=>(float)$batch->quantity_remaining])->all()]);
        }
        if (!$groups->has('') && ($gap>.00001 || $groups->isEmpty())) {
            $item->expiryCounts()->create(['expired_date'=>null,'system_quantity'=>max(0,$gap),
                'physical_quantity'=>max(0,$gap),'batch_snapshot'=>[]]);
        }
    }

    public function saveCounts(StockOpnameItem $item, array $data): void
    {
        $rows=$data['expiries'] ?? [];
        if (!$rows) $this->invalid('Isi rincian kedaluwarsa untuk '.$item->product->name.'.');
        $existing=$item->expiryCounts()->lockForUpdate()->get()->keyBy('id');
        $seen=[]; $dates=[]; $total=0;
        foreach ($rows as $row) {
            $id=$row['id'] ?? null;
            $date=$row['expired_date'] ?? null;
            $date=$date ?: null;
            $dateKey=$date ?? '';
            if (isset($dates[$dateKey])) $this->invalid('Tanggal kedaluwarsa '.$item->product->name.' berulang. Gabungkan jumlah pada tanggal yang sama.');
            $dates[$dateKey]=true;
            if ($id) {
                $entry=$existing->get($id);
                if (!$entry || isset($seen[$id]) || $entry->expired_date?->toDateString()!==$date) {
                    $this->invalid('Rincian kedaluwarsa tidak sesuai dengan barang dalam opname ini.');
                }
                $seen[$id]=true;
            } else {
                if (!$date) $this->invalid('Tanggal wajib diisi untuk rincian kedaluwarsa baru.');
                $entry=$item->expiryCounts()->make(['expired_date'=>$date,'system_quantity'=>0,'batch_snapshot'=>[]]);
            }
            $quantity=round((float)$row['physical_quantity'],4);
            $total=round($total+$quantity,4);
            if ($total>99999999999.9999) $this->invalid('Total stok fisik melebihi batas penyimpanan.');
            $entry->fill(['physical_quantity'=>$quantity])->save();
        }
        if (count($seen)!==$existing->count()) $this->invalid('Semua rincian kedaluwarsa lama wajib disertakan. Isi 0 jika stok fisiknya habis.');
        $item->update(['physical_quantity'=>$total,'difference'=>$total-(float)$item->system_quantity]);
    }

    public function apply(StockOpnameItem $item, StockOpname $opname, StockService $stocks): void
    {
        $stock=Stock::where('product_id',$item->product_id)->where('location_id',$opname->location_id)->lockForUpdate()->first();
        if (abs((float)($stock?->quantity ?? 0)-(float)$item->system_quantity)>.00001) $this->stale($item);
        $batches=StockBatch::where('product_id',$item->product_id)->where('location_id',$opname->location_id)
            ->where('quantity_remaining','>',0)->orderByDesc('received_date')->orderByDesc('id')->lockForUpdate()->get();
        $counts=$item->expiryCounts()->lockForUpdate()->get();
        if ($counts->contains(fn ($entry)=>!$entry->expired_date && (float)$entry->physical_quantity>0)) {
            $this->invalid('Tanggal kedaluwarsa '.$item->product->name.' belum lengkap. Isi 0 pada rincian tanpa tanggal, lalu tambahkan jumlah fisik pada tanggal yang sesuai.');
        }
        $expected=[];
        foreach ($counts as $entry) foreach ($entry->batch_snapshot as $id=>$quantity) $expected[(int)$id]=(float)$quantity;
        if (count($expected)!==$batches->count()) $this->stale($item);
        foreach ($batches as $batch) {
            if (!isset($expected[$batch->id]) || abs($expected[$batch->id]-(float)$batch->quantity_remaining)>.00001) $this->stale($item);
            $entry=$counts->first(fn ($row)=>array_key_exists($batch->id,$row->batch_snapshot));
            if ($entry?->expired_date?->toDateString()!==$batch->expired_date?->toDateString()) $this->stale($item);
        }
        $changed=false;
        foreach ($counts as $entry) {
            $rows=$batches->filter(fn ($batch)=>$batch->expired_date?->toDateString()===$entry->expired_date?->toDateString());
            $delta=round((float)$entry->physical_quantity-(float)$rows->sum('quantity_remaining'),4);
            $changed=$changed || abs((float)$entry->physical_quantity-(float)$entry->system_quantity)>.00001;
            if ($delta>.00001) {
                StockBatch::create(['product_id'=>$item->product_id,'location_id'=>$opname->location_id,
                    'received_date'=>$opname->opname_date,'expired_date'=>$entry->expired_date,
                    'quantity_initial'=>$delta,'quantity_remaining'=>$delta]);
            } elseif ($delta<-.00001) {
                $remaining=-$delta;
                foreach ($rows as $batch) {
                    $used=min($remaining,(float)$batch->quantity_remaining);
                    $batch->update(['quantity_remaining'=>round((float)$batch->quantity_remaining-$used,4)]);
                    $remaining=round($remaining-$used,4);
                    if ($remaining<=.00001) break;
                }
            }
        }
        $stocks->adjustStock($item->product,$opname->location,(float)$item->physical_quantity,$opname,$opname->opname_date,$opname->opname_number);
        if ($changed && abs((float)$item->difference)<.00001) {
            StockMovement::create(['product_id'=>$item->product_id,'location_id'=>$opname->location_id,
                'movement_type'=>'opname_adjustment','quantity'=>0,'balance_before'=>$item->system_quantity,
                'balance_after'=>$item->physical_quantity,'movement_date'=>$opname->opname_date,
                'reference_type'=>$opname->getMorphClass(),'reference_id'=>$opname->id,'reference_number'=>$opname->opname_number,
                'notes'=>'Penyesuaian rincian kedaluwarsa; jumlah total tetap.','created_by'=>auth()->id()]);
        }
    }

    private function stale(StockOpnameItem $item): never
    {
        $this->invalid('Stok '.$item->product->name.' berubah sejak draf dibuat. Buat draf opname baru sebelum menyesuaikan stok.');
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['items'=>$message]);
    }
}
