@extends('report.pdf.layout')
@section('report-title')Laporan Penjualan
@endsection
@section('report-period')Periode {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} s.d. {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}
@endsection
@section('report-body')
@php($reportAmount = fn ($sale) => (float) ($sale->report_total_amount ?? $sale->total_amount))
<div class="summary-line">Transaksi berstatus lunas</div>
@if($cashier ?? null)<div class="summary-line">Kasir: {{ $cashier->name }}</div>@endif
@if($category ?? null)<div class="summary-line">Kategori: {{ $category->name }}. Nominal hanya mencakup barang dalam kategori terpilih.</div>@endif
<table class="cards"><tr><td><div class="label">TOTAL PENJUALAN</div><div class="value">Rp {{ number_format($sales->sum($reportAmount),0,',','.') }}</div></td><td><div class="label">JUMLAH TRANSAKSI</div><div class="value">{{ $sales->count() }}</div></td><td><div class="label">TUNAI</div><div class="value">Rp {{ number_format($sales->where('payment_method','tunai')->sum($reportAmount),0,',','.') }}</div></td><td><div class="label">TRANSFER</div><div class="value">Rp {{ number_format($sales->where('payment_method','transfer')->sum($reportAmount),0,',','.') }}</div></td></tr></table>
<table class="data"><thead><tr><th style="width:21%">Invoice</th><th style="width:14%">Tanggal</th><th style="width:30%">Pelanggan</th><th class="center" style="width:15%">Pembayaran</th><th class="right" style="width:20%">{{ ($category ?? null) ? 'Total Kategori' : 'Total' }}</th></tr></thead><tbody>
@forelse($sales as $sale)<tr><td>{{ $sale->sale_number }}</td><td>{{ $sale->sale_date->format('d/m/Y') }}</td><td>{{ $sale->customer_name ?? 'Umum' }}</td><td class="center">{{ ucfirst($sale->payment_method) }}</td><td class="right">Rp {{ number_format($reportAmount($sale),0,',','.') }}</td></tr>@empty<tr><td colspan="5" class="center">Tidak ada data</td></tr>@endforelse
</tbody><tfoot><tr><td colspan="4">TOTAL</td><td class="right">Rp {{ number_format($sales->sum($reportAmount),0,',','.') }}</td></tr></tfoot></table>

@endsection
