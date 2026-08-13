<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><style>
@page{margin:24px}body{font-family:DejaVu Sans,sans-serif;font-size:9px;color:#27324a}h1{margin:0;color:#c8960c;font-size:18px}.meta{color:#6b7280;margin:4px 0 16px}.summary{margin-bottom:10px;font-weight:bold}table{width:100%;border-collapse:collapse}th{background:#27324a;color:#fff;padding:7px 5px;text-align:left}td{padding:6px 5px;border-bottom:1px solid #ddd}.right{text-align:right}.center{text-align:center}.low{color:#dc3545;font-weight:bold}.safe{color:#198754;font-weight:bold}tfoot td{background:#f3f4f6;font-weight:bold}
</style></head><body>
<h1>Toko Golden</h1><div class="meta">Laporan Stok · Dicetak {{ now()->format('d/m/Y H:i') }}</div>
<div class="summary">Total jenis barang: {{ $products->count() }}</div>
<table><thead><tr><th>Barang</th><th>Kategori</th><th>Satuan</th><th class="right">Total Stok</th><th class="right">Min. Stok</th><th class="right">Nilai Stok</th><th class="center">Status</th></tr></thead><tbody>
@php $grandValue=0; @endphp
@forelse($products as $product)
@php
$total=(float)$product->stocks->sum('quantity'); $min=(float)$product->min_stock_total;
$value=$product->can_retail && (float)$product->conversion_factor>0 ? ($total/(float)$product->conversion_factor)*(float)$product->buy_price : $total*(float)$product->buy_price;
$grandValue+=$value; $low=$total<=$min;
@endphp
<tr><td><strong>{{ $product->name }}</strong></td><td>{{ $product->category->name ?? '-' }}</td><td>{{ $product->can_retail ? ($product->retailUnit->name ?? $product->buyUnit->name) : $product->buyUnit->name }}</td><td class="right">{{ $product->displayStock($total) }}</td><td class="right">{{ $product->displayStock($min) }}</td><td class="right">Rp {{ number_format($value,0,',','.') }}</td><td class="center {{ $low?'low':'safe' }}">{{ $low?'Rendah':'Aman' }}</td></tr>
@empty<tr><td colspan="7" class="center">Tidak ada data</td></tr>@endforelse
</tbody><tfoot><tr><td colspan="5">TOTAL NILAI PERSEDIAAN</td><td class="right">Rp {{ number_format($grandValue,0,',','.') }}</td><td></td></tr></tfoot></table>
</body></html>
