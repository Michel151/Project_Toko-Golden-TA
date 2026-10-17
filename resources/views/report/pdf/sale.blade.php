@extends('report.pdf.layout')
@section('report-title')Laporan Penjualan
@endsection
@section('report-period')Periode {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} s.d. {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}
@endsection
@section('report-body')
<div class="summary-line">Transaksi berstatus lunas</div>
<table class="cards"><tr><td><div class="label">TOTAL PENJUALAN</div><div class="value">Rp {{ number_format($sales->sum('total_amount'),0,',','.') }}</div></td><td><div class="label">JUMLAH TRANSAKSI</div><div class="value">{{ $sales->count() }}</div></td><td><div class="label">TUNAI</div><div class="value">Rp {{ number_format($sales->where('payment_method','tunai')->sum('total_amount'),0,',','.') }}</div></td><td><div class="label">TRANSFER</div><div class="value">Rp {{ number_format($sales->where('payment_method','transfer')->sum('total_amount'),0,',','.') }}</div></td></tr></table>
<table class="data"><thead><tr><th style="width:21%">Invoice</th><th style="width:14%">Tanggal</th><th style="width:30%">Pelanggan</th><th class="center" style="width:15%">Pembayaran</th><th class="right" style="width:20%">Total</th></tr></thead><tbody>
@forelse($sales as $sale)<tr><td>{{ $sale->sale_number }}</td><td>{{ $sale->sale_date->format('d/m/Y') }}</td><td>{{ $sale->customer_name ?? 'Umum' }}</td><td class="center">{{ ucfirst($sale->payment_method) }}</td><td class="right">Rp {{ number_format($sale->total_amount,0,',','.') }}</td></tr>@empty<tr><td colspan="5" class="center">Tidak ada data</td></tr>@endforelse
</tbody><tfoot><tr><td colspan="4">TOTAL</td><td class="right">Rp {{ number_format($sales->sum('total_amount'),0,',','.') }}</td></tr></tfoot></table>

@endsection
