@extends('report.pdf.layout')
@section('report-title')Laporan Laba Kotor
@endsection
@section('report-period')Periode {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} s.d. {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}
@endsection
@section('report-body')
<div class="muted">Nota lunas; HPP historis; belum dikurangi biaya operasional.</div>
@if($incompleteCount)<div class="notice">{{ $incompleteCount }} nota memiliki HPP belum lengkap. Total laba dan margin belum dapat dipastikan. HPP yang tampil hanya biaya tercatat.</div>@endif
<table class="summary"><tr><td><div class="label">PENJUALAN LUNAS ({{ $transactionCount }} NOTA)</div><div class="value">Rp {{ number_format($totalRevenue,0,',','.') }}</div></td><td><div class="label">HPP TERCATAT</div><div class="value">Rp {{ number_format($totalCost,0,',','.') }}</div></td><td><div class="label">LABA KOTOR</div><div class="value">{{ $grossProfit === null ? 'Belum lengkap' : 'Rp '.number_format($grossProfit,0,',','.') }}</div></td><td><div class="label">MARGIN KOTOR</div><div class="value">{{ $grossMargin === null ? '-' : number_format($grossMargin,2,',','.').'%' }}</div></td></tr></table>
<table class="data"><thead><tr><th style="width:16%">Invoice</th><th style="width:14%">Tanggal / Waktu</th><th style="width:18%">Pelanggan / Kasir</th><th style="width:14%" class="right">Penjualan</th><th style="width:14%" class="right">HPP Tercatat</th><th style="width:14%" class="right">Laba Kotor</th><th style="width:10%" class="right">Margin</th></tr></thead><tbody>
@forelse($sales as $sale)@php $revenue=(float)$sale->total_amount; $profit=$sale->incomplete_cost ? null : $revenue-(float)$sale->report_cost; @endphp
<tr><td>{{ $sale->sale_number }}</td><td>{{ $sale->sale_date->format('d/m/Y') }}<div class="muted">{{ $sale->transaction_time_label }}</div></td><td>{{ $sale->customer_name ?: 'Umum' }}<div class="muted">{{ $sale->createdBy?->name ?? '-' }}</div></td><td class="right">Rp {{ number_format($revenue,0,',','.') }}</td><td class="right">Rp {{ number_format($sale->report_cost,0,',','.') }}@if($sale->incomplete_cost)<div class="danger">HPP belum lengkap</div>@endif</td><td class="right {{ $profit !== null && $profit < 0 ? 'danger' : '' }}">{{ $profit === null ? '-' : 'Rp '.number_format($profit,0,',','.') }}</td><td class="right">{{ $profit !== null && $revenue > 0 ? number_format($profit/$revenue*100,2,',','.').'%' : '-' }}</td></tr>
@empty<tr><td colspan="7">Tidak ada nota lunas pada periode ini.</td></tr>@endforelse
</tbody><tfoot><tr><td colspan="3">TOTAL PERIODE</td><td class="right">Rp {{ number_format($totalRevenue,0,',','.') }}</td><td class="right">Rp {{ number_format($totalCost,0,',','.') }}</td><td class="right">{{ $grossProfit === null ? '-' : 'Rp '.number_format($grossProfit,0,',','.') }}</td><td class="right">{{ $grossMargin === null ? '-' : number_format($grossMargin,2,',','.').'%' }}</td></tr></tfoot></table>


@endsection
