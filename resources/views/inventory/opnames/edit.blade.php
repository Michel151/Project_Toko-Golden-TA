@extends('layouts.app')
@section('title', 'Isi Stock Opname')
@section('page-title', 'Isi Jumlah Fisik - ' . $opname->opname_number)
@push('styles')
<style>
.opname-count-table{min-width:740px}.opname-count-table>tbody>tr>td{vertical-align:middle;padding:7px 10px}
.opname-expiry-cell{background:#f8faf9;padding:6px 12px 10px!important}.opname-expiry-cell .table{min-width:680px}
.opname-quantity{width:max-content;flex-wrap:nowrap}.opname-quantity .input-group{width:128px;min-width:128px;flex:0 0 128px}.opname-quantity .form-control{min-width:0}.opname-quantity .input-group-text{max-width:76px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.expiry-list td{vertical-align:middle}.opname-difference{min-width:115px}.expiry-date{max-width:170px}
.opname-search{max-width:320px}.expiry-toggle{display:inline-flex;align-items:center;gap:6px;padding:2px 0;border:0;background:none;color:var(--bs-success);font-size:12px}.expiry-toggle[aria-expanded="true"] .bi-chevron-right{transform:rotate(90deg)}
.opname-count-table [hidden]{display:none!important}.opname-actions{position:sticky;bottom:0;background:var(--bs-body-bg,#fff);padding:10px 0;z-index:2}
</style>
@endpush
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><h5 class="fw-bold mb-1">{{ $opname->opname_number }}</h5><div class="small text-muted">{{ $opname->location->name }} | {{ $opname->opname_date->format('d/m/Y') }}</div></div>
    <a href="{{ route('inventory.opnames.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
</div>
<form method="POST" action="{{ route('inventory.opnames.update', $opname) }}" data-complete-url="{{ route('inventory.opnames.complete',$opname) }}" id="opnameForm">
    <div class="small text-muted mb-3">Petugas Opname: <strong class="text-body text-break">{{ collect($opname->participants ?? [])->pluck('name')->implode(', ') ?: 'Belum dicatat' }}</strong></div>
    @csrf @method('PUT')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <span class="small text-muted" id="opnameItemCount">{{ $opname->items->count() }} barang</span>
        <input type="search" class="form-control form-control-sm opname-search" id="opnameSearch" placeholder="Cari barang..." aria-label="Cari barang opname">
    </div>
    <div class="table-responsive bg-white border rounded">
        <table class="table table-hover mb-0 opname-count-table">
            <thead><tr><th>Barang</th><th class="text-end">Stok Sistem</th><th style="width:310px">Stok Fisik</th><th class="text-end">Selisih</th></tr></thead>
            <tbody>
            @foreach($opname->items as $item)
                @php
                    $product=$item->product;
                    $displayUnits=\App\Support\OpnameQuantity::units($product);
                    $baseName=$product->baseProductUnit?->unit?->name ?? ($product->can_retail ? $product->retailUnit?->name : $product->buyUnit?->name) ?? 'Unit';
                    $value=old("items.{$item->id}.physical_quantity",$item->physical_quantity);
                @endphp
                <tr data-opname-item="{{ $item->id }}" data-system="{{ $item->system_quantity }}" data-unit="{{ $baseName }}" data-display-units="{{ json_encode($displayUnits) }}">
                    <td><strong class="opname-product-name">{{ $product->name }}</strong>@if($item->tracks_expiry)<div><button type="button" class="expiry-toggle" aria-expanded="false" aria-controls="expiry-{{ $item->id }}"><i class="bi bi-chevron-right" aria-hidden="true"></i><span class="expiry-count">{{ $item->expiryCounts->count() }} kedaluwarsa</span></button></div>@endif</td>
                    <td class="text-end">{{ \App\Support\OpnameQuantity::format((float)$item->system_quantity,$displayUnits) }}</td>
                    <td>
                        @if($item->tracks_expiry)
                            <input class="item-physical" type="hidden" name="items[{{ $item->id }}][physical_quantity]" value="{{ $value }}"><strong class="item-total">{{ \App\Support\OpnameQuantity::format((float)$value,$displayUnits) }}</strong>
                        @else
                            <input class="item-physical" type="hidden" value="{{ $value }}">
                            @include('inventory.opnames.quantity-input',['field'=>"items[{$item->id}][physical_quantity]",'value'=>$value])
                        @endif
                    </td>
                    <td class="text-end fw-semibold opname-difference"><span class="item-difference"></span>
                        @if(auth()->user()->isOwner() && app(\App\Services\MovingAverageCostService::class)->canTrack($item->product))
                            @php
                                $valuationCost = old('items.'.$item->id.'.valuation_unit_cost', $item->valuation_unit_cost);
                                if (is_string($valuationCost) && str_contains($valuationCost, '.')) {
                                    $valuationCost = rtrim(rtrim($valuationCost, '0'), '.');
                                }
                            @endphp
                            <div data-valuation-field>
                            <label class="d-block small fw-normal mt-1" for="opnameCost{{ $item->id }}">Nilai perolehan penambahan / satuan dasar (Rp)</label>
                            <input id="opnameCost{{ $item->id }}" type="text" inputmode="decimal" autocomplete="off" class="form-control form-control-sm text-end" data-valuation-display aria-describedby="opnameCostHelp{{ $item->id }}">
                            <input type="hidden" name="items[{{ $item->id }}][valuation_unit_cost]" value="{{ $valuationCost }}" data-valuation-value>
                            <div id="opnameCostHelp{{ $item->id }}" class="small text-muted fw-normal">Contoh: 2.300 atau 2.300,50</div>
                            </div>
                        @endif
                    </td>
                </tr>
                @if($item->tracks_expiry)
                    @php
                        $expiryRows=old("items.{$item->id}.expiries",$item->expiryCounts->map(fn($entry)=>[
                            'id'=>$entry->id,'expired_date'=>$entry->expired_date?->toDateString(),
                            'system_quantity'=>$entry->system_quantity,'physical_quantity'=>$entry->physical_quantity,
                        ])->all());
                        foreach($expiryRows as &$expiryRow) {
                            if(!empty($expiryRow['id'])) {
                                $saved=$item->expiryCounts->firstWhere('id',$expiryRow['id']);
                                $expiryRow['system_quantity']=$saved?->system_quantity ?? 0;
                            }
                        }
                        unset($expiryRow);
                    @endphp
                    <tr id="expiry-{{ $item->id }}" data-expiry-item="{{ $item->id }}" data-next-index="{{ count($expiryRows) }}" hidden>
                        <td colspan="4" class="opname-expiry-cell">
                            <div class="d-flex justify-content-end mb-1"><button class="btn btn-sm btn-outline-success add-expiry" type="button"><i class="bi bi-plus-lg me-1"></i>Tambah Kedaluwarsa</button></div>
                            <table class="table table-sm mb-0"><caption class="visually-hidden">Rincian Kedaluwarsa {{ $product->name }}</caption><thead><tr><th style="width:24%">Kedaluwarsa</th><th class="text-end" style="width:18%">Stok Sistem</th><th style="width:34%">Stok Fisik</th><th class="text-end" style="width:18%">Selisih</th><th style="width:6%"></th></tr></thead>
                                <tbody class="expiry-list">@foreach($expiryRows as $index=>$row)@include('inventory.opnames.expiry-row')@endforeach</tbody>
                            </table>
                            <template>@include('inventory.opnames.expiry-row',['index'=>'__INDEX__','row'=>['physical_quantity'=>0]])</template>
                        </td>
                    </tr>
                @endif
            @endforeach
            </tbody>
        </table>
    </div>
    <div id="opnameEmpty" class="text-center text-muted py-3" hidden>Barang tidak ditemukan.</div>
    <div class="d-flex flex-wrap gap-2 mt-2 opname-actions">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan Draf</button>
        <button type="button" class="btn btn-success" id="btnSelesaikan"><i class="bi bi-check-circle me-1"></i>Selesaikan Opname</button>
        <a href="{{ route('inventory.opnames.index') }}" class="btn btn-outline-secondary">Batal</a>
    </div>
</form>
@push('scripts')
@include('inventory.opnames.count-scripts')
@endpush
@endsection
