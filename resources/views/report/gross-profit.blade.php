@extends('layouts.app')
@section('title', 'Laporan Laba Kotor')
@section('page-title', 'Laporan Laba Kotor')
@push('styles')
<style>
.profit-filter{display:flex;flex-wrap:wrap;gap:.6rem;align-items:end}.profit-filter>div{min-width:0}
.profit-summary .card-body{min-width:0;overflow-wrap:anywhere}.profit-value{font-size:1.2rem;font-weight:700}
.profit-section{background:#fff;border-top:2px solid var(--app-border)}
.profit-section .table{min-width:880px;font-size:.85rem;margin:0}.profit-section td{vertical-align:middle}
.profit-section .money{white-space:nowrap;text-align:right}
.profit-section .table th{white-space:nowrap}
@media(max-width:575px){.profit-value{font-size:1.05rem}.profit-filter>div{flex:1 1 125px}.profit-summary .card-body{padding:.85rem}}
</style>
@endpush
@section('content')
<div class="report-heading d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3"><div class="d-flex align-items-center gap-3"><div class="report-icon"><i class="bi bi-calculator"></i></div><div><h5 class="fw-bold mb-1">Laporan Laba Kotor</h5><div class="text-white-50 small">Nota lunas - HPP historis - belum dikurangi biaya operasional</div></div></div><span class="badge bg-success">{{ $transactionCount }} nota lunas</span></div>
<div class="report-toolbar d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
    <form class="profit-filter" method="GET" action="{{ route('report.gross-profit') }}">
        <div><label for="profitFrom" class="form-label small mb-1">Dari Tanggal</label><input id="profitFrom" name="from" type="date" class="form-control form-control-sm" value="{{ $from }}" required></div>
        <div><label for="profitTo" class="form-label small mb-1">Sampai Tanggal</label><input id="profitTo" name="to" type="date" class="form-control form-control-sm" value="{{ $to }}" min="{{ $from }}" required></div>
        <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-funnel me-1"></i>Terapkan</button>
        <a href="{{ route('report.gross-profit',['from'=>now('Asia/Jakarta')->toDateString(),'to'=>now('Asia/Jakarta')->toDateString()]) }}" class="btn btn-sm btn-outline-secondary">Hari Ini</a>
        <a href="{{ route('report.gross-profit') }}" class="btn btn-sm btn-outline-secondary">Bulan Ini</a>
    </form>
    <div class="btn-group btn-group-sm"><a href="{{ route('report.gross-profit.pdf',compact('from','to')) }}" class="btn btn-outline-danger" target="_blank" rel="noopener"><i class="bi bi-file-pdf me-1"></i>PDF</a><a href="{{ route('report.gross-profit.excel',compact('from','to')) }}" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a></div>
</div>
@if($incompleteCount)<div class="alert alert-warning d-flex gap-2 align-items-start" role="alert"><i class="bi bi-exclamation-triangle"></i><div><strong>{{ $incompleteCount }} nota memiliki HPP belum lengkap.</strong> Total laba dan margin belum dapat dipastikan. Nilai HPP yang tampil hanya biaya yang sudah tercatat.</div></div>@endif
<div class="row g-3 profit-summary mb-3">
    <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="small text-muted"><i class="bi bi-cash-stack text-success me-1"></i>Penjualan Lunas</div><div class="profit-value">Rp {{ number_format($totalRevenue,0,',','.') }}</div><div class="small text-muted">{{ $transactionCount }} nota</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="small text-muted"><i class="bi bi-box-seam text-primary me-1"></i>HPP Tercatat</div><div class="profit-value">Rp {{ number_format($totalCost,0,',','.') }}</div><div class="small text-muted">Biaya pada saat transaksi</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="small text-muted"><i class="bi bi-calculator text-info me-1"></i>Laba Kotor</div><div class="profit-value {{ $grossProfit !== null && $grossProfit < 0 ? 'text-danger' : '' }}">{{ $grossProfit === null ? 'Belum lengkap' : 'Rp '.number_format($grossProfit,0,',','.') }}</div><div class="small text-muted">Penjualan dikurangi HPP</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body"><div class="small text-muted"><i class="bi bi-percent text-warning me-1"></i>Margin Kotor</div><div class="profit-value">{{ $grossMargin === null ? '-' : number_format($grossMargin,2,',','.').'%' }}</div><div class="small text-muted">Laba kotor / penjualan</div></div></div></div>
</div>
<section class="profit-section" aria-labelledby="profitRowsHeading">
    <div class="p-3 d-flex flex-wrap justify-content-between gap-2"><h6 id="profitRowsHeading" class="fw-bold mb-0">Rincian per Nota</h6><span class="small text-muted">{{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} s.d. {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}</span></div>
    <div class="table-responsive" tabindex="0" aria-label="Rincian laba kotor per nota"><table class="table table-hover report-table"><thead><tr><th>Invoice</th><th>Tanggal / Waktu</th><th>Pelanggan / Kasir</th><th class="text-end">Penjualan</th><th class="text-end">HPP Tercatat</th><th class="text-end">Laba Kotor</th><th class="text-end">Margin</th></tr></thead><tbody>
    @forelse($sales as $sale)
        @php $revenue=(float)$sale->total_amount; $profit=$sale->incomplete_cost ? null : $revenue-(float)$sale->report_cost; @endphp
        <tr><td>@if(auth()->user()->canAccessRoute('sale.sales.show'))<a class="text-decoration-none fw-semibold" href="{{ route('sale.sales.show',$sale) }}">{{ $sale->sale_number }}</a>@else{{ $sale->sale_number }}@endif</td><td>{{ $sale->sale_date->format('d/m/Y') }}<div class="small text-muted">{{ $sale->transaction_time_label }}</div></td><td>{{ $sale->customer_name ?: 'Umum' }}<div class="small text-muted">{{ $sale->createdBy?->name ?? '-' }}</div></td><td class="money">Rp {{ number_format($revenue,0,',','.') }}</td><td class="money">Rp {{ number_format($sale->report_cost,0,',','.') }}@if($sale->incomplete_cost)<div class="small text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1"></i>HPP belum lengkap</div>@endif</td><td class="money fw-semibold {{ $profit !== null && $profit < 0 ? 'text-danger' : 'text-success' }}">{{ $profit === null ? '-' : 'Rp '.number_format($profit,0,',','.') }}</td><td class="money">{{ $profit !== null && $revenue > 0 ? number_format($profit/$revenue*100,2,',','.').'%' : '-' }}</td></tr>
    @empty<tr><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-inbox d-block fs-3 mb-2"></i>Tidak ada nota lunas pada periode ini.</td></tr>@endforelse
    </tbody><tfoot><tr class="fw-bold"><td colspan="3">TOTAL PERIODE</td><td class="money">Rp {{ number_format($totalRevenue,0,',','.') }}</td><td class="money">Rp {{ number_format($totalCost,0,',','.') }}</td><td class="money">{{ $grossProfit === null ? '-' : 'Rp '.number_format($grossProfit,0,',','.') }}</td><td class="money">{{ $grossMargin === null ? '-' : number_format($grossMargin,2,',','.').'%' }}</td></tr></tfoot></table></div>
    @if($sales->hasPages())<div class="p-3 border-top">{{ $sales->links() }}</div>@endif
</section>
@endsection
@push('scripts')
<script>
document.getElementById('profitFrom').addEventListener('change',event=>document.getElementById('profitTo').min=event.target.value);
</script>
@endpush
