@extends('report.pdf.layout')
@section('report-title'){{ $reportTitle ?? 'Laporan Stok' }}
@endsection
@section('report-period')Posisi stok per {{ now('Asia/Jakarta')->format('d/m/Y H:i') }} WIB
@endsection
@section('report-body')
@if(!empty($expiryFilterLabel))<div class="summary-line">Filter kedaluwarsa: {{ $expiryFilterLabel }}</div>@endif
<div class="summary-line">Total jenis barang: {{ $products->count() }}</div>
<table class="data compact"><thead><tr><th style="width:24%">Barang</th><th style="width:16%">Kategori</th><th style="width:6%">Satuan Dasar</th><th class="right" style="width:9%">Total Fisik</th><th class="right" style="width:9%">Layak Jual</th><th class="right" style="width:9%">Kedaluwarsa</th><th class="right" style="width:8%">Min. Stok</th><th class="right" style="width:12%">Nilai Stok</th><th class="center" style="width:7%">Status</th></tr></thead><tbody>
@php $grandValue=0; @endphp
@forelse($products as $product)
@php
$total=(float)$product->stocks->sum('quantity');
$sellable=$product->is_expirable ? (float)$product->stockBatches->filter(fn($batch)=>(float)$batch->quantity_remaining>0 && (!$batch->expired_date || $batch->expired_date->gte(today())))->sum(fn($batch)=>(float)$batch->quantity_remaining) : $total;
$expired=$product->is_expirable ? (float)$product->stockBatches->filter(fn($batch)=>(float)$batch->quantity_remaining>0 && $batch->expired_date && $batch->expired_date->lt(today()))->sum(fn($batch)=>(float)$batch->quantity_remaining) : 0;
$min=(float)$product->min_stock_total;
$baseUnitName=$product->productUnits->firstWhere('is_base_unit', true)?->unit?->name ?? ($product->can_retail ? ($product->retailUnit->name ?? $product->buyUnit->name) : $product->buyUnit->name);
$purchaseUnit=$product->productUnits->firstWhere('is_default_purchase', true);
$baseCost=$purchaseUnit && (float)$purchaseUnit->conversion_to_base>0 ? (float)$purchaseUnit->buy_price/(float)$purchaseUnit->conversion_to_base : (float)$product->buy_price;
$value=$total*$baseCost;
$grandValue+=$value; $low=$sellable<=$min;
@endphp
<tr><td><strong>{{ $product->name }}</strong></td><td>{{ $product->category->name ?? '-' }}</td><td>{{ $baseUnitName }}</td><td class="right">{{ $product->displayStock($total) }}</td><td class="right">{{ $product->displayStock($sellable) }}</td><td class="right {{ $expired>0?'low':'' }}">{{ $product->displayStock($expired) }}</td><td class="right">{{ $product->displayStock($min) }}</td><td class="right">Rp {{ number_format($value,0,',','.') }}</td><td class="center {{ $low?'low':'safe' }}">{{ $low?'Rendah':'Aman' }}</td></tr>
@empty<tr><td colspan="9" class="center">Tidak ada data</td></tr>@endforelse
</tbody><tfoot><tr><td colspan="7">TOTAL NILAI PERSEDIAAN</td><td class="right">Rp {{ number_format($grandValue,0,',','.') }}</td><td></td></tr></tfoot></table>

@endsection
