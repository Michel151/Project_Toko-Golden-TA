@extends('report.pdf.layout')
@section('report-header-centered', '1')
@section('report-title'){{ $reportTitle ?? 'Laporan Stok' }}
@endsection
@section('report-period')Posisi stok per {{ now('Asia/Jakarta')->format('d/m/Y H:i') }} WIB
@endsection
@section('report-body')
<style>
    .stock-report th, .stock-report td { padding-left: 3px; padding-right: 3px; }
</style>
@if(!empty($expiryFilterLabel))<div class="summary-line">Filter kedaluwarsa: {{ $expiryFilterLabel }}</div>@endif
<div class="summary-line">Total jenis barang: {{ $products->count() }}</div>
@php
    $grandValue = 0;
    $locationCount = $locations->count();
    $widthFactor = 100 / (100 + 8 * $locationCount);
@endphp
<table class="data compact stock-report">
    <thead><tr>
        <th style="width:{{ 24 * $widthFactor }}%">Barang</th>
        <th style="width:{{ 16 * $widthFactor }}%">Kategori</th>
        <th style="width:{{ 6 * $widthFactor }}%">Satuan Dasar</th>
        @foreach($locations as $location)
            <th class="right" style="width:{{ 8 * $widthFactor }}%">{{ $location->name }}</th>
        @endforeach
        <th class="right" style="width:{{ 9 * $widthFactor }}%">Total Fisik</th>
        <th class="right" style="width:{{ 9 * $widthFactor }}%">Layak Jual</th>
        <th class="right" style="width:{{ 9 * $widthFactor }}%">Kedaluwarsa</th>
        <th class="right" style="width:{{ 8 * $widthFactor }}%">Min. Stok</th>
        <th class="right" style="width:{{ 12 * $widthFactor }}%">Nilai Stok</th>
        <th class="center" style="width:{{ 7 * $widthFactor }}%">Status</th>
    </tr></thead>
    <tbody>
@forelse($products as $product)
@php
$total=(float)$product->stocks->sum('quantity');
$sellable=$product->is_expirable ? (float)$product->stockBatches->filter(fn($batch)=>(float)$batch->quantity_remaining>0 && (!$batch->expired_date || $batch->expired_date->gte(today())))->sum(fn($batch)=>(float)$batch->quantity_remaining) : $total;
$expired=$product->is_expirable ? (float)$product->stockBatches->filter(fn($batch)=>(float)$batch->quantity_remaining>0 && $batch->expired_date && $batch->expired_date->lt(today()))->sum(fn($batch)=>(float)$batch->quantity_remaining) : 0;
$min=(float)$product->min_stock_total;
$baseUnitName=$product->productUnits->firstWhere('is_base_unit', true)?->unit?->name ?? ($product->can_retail ? ($product->retailUnit->name ?? $product->buyUnit->name) : $product->buyUnit->name);
$value=$product->inventoryValue();
$grandValue+=$value; $low=$sellable<=$min;
@endphp
<tr>
    <td><strong>{{ $product->name }}</strong></td><td>{{ $product->category->name ?? '-' }}</td><td>{{ $baseUnitName }}</td>
    @foreach($locations as $location)
        <td class="right">{{ $product->displayStock($product->stockAvailability($location->id)['sellable']) }}</td>
    @endforeach
    <td class="right">{{ $product->displayStock($total) }}</td><td class="right">{{ $product->displayStock($sellable) }}</td><td class="right {{ $expired>0?'low':'' }}">{{ $product->displayStock($expired) }}</td><td class="right">{{ $product->displayStock($min) }}</td><td class="right">Rp {{ number_format($value,0,',','.') }}</td><td class="center {{ $low?'low':'safe' }}">{{ $low?'Rendah':'Aman' }}</td>
</tr>
@empty<tr><td colspan="{{ 9 + $locationCount }}" class="center">Tidak ada data</td></tr>@endforelse
</tbody><tfoot><tr><td colspan="{{ 7 + $locationCount }}">TOTAL NILAI PERSEDIAAN</td><td class="right">Rp {{ number_format($grandValue,0,',','.') }}</td><td></td></tr></tfoot></table>

@endsection
