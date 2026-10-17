@extends('report.pdf.layout')
@section('report-title', 'Kartu Stok Barang')
@section('report-period')
{{ $product->name }} | {{ $product->category->name ?? '-' }}<br>
Periode {{ date('d/m/Y',strtotime($from)) }} s.d. {{ date('d/m/Y',strtotime($to)) }} | Lokasi: {{ $locationId ? ($locations->firstWhere('id',$locationId)?->name ?? '-') : 'Semua Lokasi' }}
@endsection
@section('report-body')
<table class="summary"><tr>
    <td><div class="label">SALDO AWAL</div><div class="value">{{ $product->displayStock($openingBalance) }}</div></td>
    <td><div class="label">BARANG MASUK</div><div class="value">{{ $product->displayStock($totalIn) }}</div></td>
    <td><div class="label">BARANG KELUAR</div><div class="value">{{ $product->displayStock($totalOut) }}</div></td>
    <td><div class="label">SALDO AKHIR</div><div class="value">{{ $product->displayStock($closingBalance) }}</div></td>
</tr></table>
<table class="data compact"><thead><tr><th style="width:9%">Tanggal</th><th style="width:15%">Referensi</th><th style="width:22%">Keterangan</th><th style="width:10%">Lokasi</th><th class="right" style="width:10%">Masuk</th><th class="right" style="width:10%">Keluar</th><th class="right" style="width:12%">Saldo</th><th style="width:12%">Petugas</th></tr></thead><tbody>
@forelse($movements as $movement)
    @php $quantity=(float)$movement->quantity; @endphp
    <tr><td>{{ $movement->movement_date->format('d/m/Y') }}</td><td>{{ $movement->reference_number ?? '-' }}</td><td>{{ $movement->type_label }}{{ $movement->notes ? ' - '.$movement->notes : '' }}</td><td>{{ $movement->location->name }}</td><td class="right">{{ $quantity>0 ? $product->displayStock($quantity) : '-' }}</td><td class="right">{{ $quantity<0 ? $product->displayStock(abs($quantity)) : '-' }}</td><td class="right">{{ $product->displayStock((float)$movement->card_balance) }}</td><td>{{ $movement->createdBy->name ?? 'Sistem' }}</td></tr>
@empty<tr><td colspan="8" class="center">Tidak ada mutasi</td></tr>@endforelse
</tbody><tfoot><tr><td colspan="4">TOTAL / SALDO AKHIR</td><td class="right">{{ $product->displayStock($totalIn) }}</td><td class="right">{{ $product->displayStock($totalOut) }}</td><td class="right">{{ $product->displayStock($closingBalance) }}</td><td></td></tr></tfoot></table>
@endsection
