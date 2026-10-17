@extends('layouts.app')
@section('title', 'Dashboard Kasir')
@section('page-title', 'Dashboard Kasir')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard-donuts.css') }}?v=1">
<style>
.cashier-metric{width:40px;height:40px;flex:0 0 40px;border-radius:8px;display:grid;place-items:center;font-size:1.2rem}
.cashier-value{font-size:1.15rem;font-weight:700;overflow-wrap:anywhere}
.cashier-section{background:#fff;padding:1rem;border-top:2px solid var(--app-border)}
.cashier-section h6{font-weight:700;margin:0}.cashier-section .table{font-size:.85rem}.cashier-section td{vertical-align:middle}
.cashier-line{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:.75rem;padding:.8rem 0;border-bottom:1px solid var(--app-border)}
.cashier-empty{padding:1.5rem .5rem;color:var(--app-muted);text-align:center}
.cashier-donut{width:174px;height:174px;flex:none;margin:1.25rem auto;border-radius:50%;display:grid;place-items:center;background:conic-gradient(#1f7a5a 0% var(--paid-end),#e7b548 var(--paid-end) var(--pending-end),#c84b45 var(--pending-end) 100%)}
.cashier-donut-center{width:128px;height:128px;border-radius:50%;background:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center}
.cashier-donut-center strong{font-size:1.85rem;line-height:1.2}.status-swatch{display:inline-block;width:10px;height:10px;flex:none;border-radius:2px}
.cashier-chart-summary{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.6fr);align-items:center;gap:.75rem;margin:1rem 0 .25rem}
.cashier-chart-summary .cashier-donut{width:min(100%,132px);height:auto;aspect-ratio:1;margin:0 auto}
.cashier-chart-summary .cashier-donut-center{width:74%;height:74%}.cashier-chart-summary .cashier-donut-center strong{font-size:1.5rem}
.cashier-chart-summary .cashier-donut-center span{font-size:.7rem}
.cashier-chart-legend-row{display:grid;grid-template-columns:10px minmax(0,1fr) auto;align-items:center;gap:.4rem;padding:.4rem 0;border-bottom:1px solid var(--app-border);font-size:.8rem}
.cashier-chart-legend-row:last-child{border-bottom:0}.cashier-chart-legend-row small{display:block;font-size:.7rem}
@media(min-width:1200px){
    .cashier-dashboard{min-height:calc(100dvh - var(--cashier-topbar-height,56px) - 2.7rem);display:flex;flex-direction:column}
    .cashier-content-row{flex:1}
    .cashier-content-row>div{display:flex;flex-direction:column}
    .cashier-content-row .cashier-section{flex:1;display:flex;flex-direction:column}
    .cashier-content-row .cashier-empty{flex:1;display:grid;place-content:center}
    .cashier-content-row .cashier-chart-summary{flex:1}
    .cashier-content-row section[aria-labelledby="paymentHeading"] .cashier-line{flex:1}
}
@media(max-width:575px){.cashier-stat .card-body{flex-direction:column;align-items:flex-start!important;gap:.5rem!important;padding:.85rem}.cashier-section{padding:.85rem}}
</style>
@endpush
@section('content')
<div class="cashier-dashboard">
<div class="cashier-hero d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <div><div class="small text-white-50 mb-1">{{ now('Asia/Jakarta')->locale('id')->translatedFormat('l, d F Y') }}</div><h5 class="fw-bold mb-0">Selamat bekerja, {{ auth()->user()->name }}</h5></div>
    @if(auth()->user()->canAccessRoute('sale.sales.create'))<a href="{{ route('sale.sales.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Transaksi Baru</a>@endif
</div>
<div class="row g-3 mb-3">
    @php $metrics=[['Penjualan Lunas Hari Ini','Rp '.number_format($cashierSalesToday,0,',','.'),'cash-coin','success',''],['Nota Lunas Hari Ini',$cashierTransactionsToday,'receipt','primary',$cashierCancelledToday.' nota dibatalkan'],['Menunggu Pembayaran',$cashierPendingCount.' nota','hourglass-split','warning','Rp '.number_format($cashierPendingAmount,0,',','.')],['Draft Keranjang',$cashierDraftCount,'pencil-square','info','Tersinkron ke server']]; @endphp
    @foreach($metrics as [$label,$value,$icon,$color,$detail])<div class="col-6 col-xl-3"><div class="card cashier-stat h-100"><div class="card-body d-flex gap-3 align-items-center"><div class="cashier-metric bg-{{ $color }} bg-opacity-10 text-{{ $color }}"><i class="bi bi-{{ $icon }}"></i></div><div><div class="text-muted small">{{ $label }}</div><div class="cashier-value">{{ $value }}</div>@if($detail)<div class="small text-muted">{{ $detail }}</div>@endif</div></div></div></div>@endforeach
</div>
@if($cashierDueSoonCount > 0)<div class="alert alert-warning py-2"><i class="bi bi-clock me-1"></i><strong>{{ $cashierDueSoonCount }} nota</strong> mencapai batas pembayaran dalam satu jam.</div>@endif
@if($cashierExpiredPendingCount > 0)<div class="alert alert-danger py-2 d-flex flex-wrap justify-content-between gap-2"><span><i class="bi bi-exclamation-triangle me-1"></i>{{ $cashierExpiredPendingCount }} nota melewati batas pembayaran dan masih berstatus pending.</span><a href="{{ route('sale.sales.index',['status'=>'pending']) }}" class="alert-link">Periksa Nota <i class="bi bi-arrow-right"></i></a></div>@endif
<div class="row g-3 cashier-content-row">
    <div class="col-xl-8">
        <section class="cashier-section mb-3" aria-labelledby="pendingHeading">
            <div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><h6 id="pendingHeading">Nota Belum Dibayar</h6><a href="{{ route('sale.sales.index',['status'=>'pending']) }}" class="small text-decoration-none">Lihat Semua <i class="bi bi-arrow-right"></i></a></div>
            @if($cashierPendingSales->isNotEmpty())<div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Nota / Pelanggan</th><th class="text-end">Total</th><th>Batas Bayar</th><th></th></tr></thead><tbody>
            @foreach($cashierPendingSales as $sale)<tr><td><a href="{{ route('sale.sales.show',$sale) }}" class="fw-semibold text-decoration-none">{{ $sale->sale_number }}</a><div class="small text-muted">{{ $sale->customer_name ?: 'Pelanggan Umum' }}</div></td><td class="text-end text-nowrap">Rp {{ number_format($sale->total_amount,0,',','.') }}</td><td class="text-nowrap {{ $sale->hold_until->lte(now('Asia/Jakarta')->addHour()) ? 'text-danger fw-semibold' : '' }}">{{ $sale->hold_until->timezone('Asia/Jakarta')->format('H:i') }} WIB</td><td><a href="{{ route('sale.sales.show',$sale) }}" class="btn btn-sm btn-outline-success"><i class="bi bi-receipt me-1"></i>{{ auth()->user()->canAccessRoute('sale.sales.complete') ? 'Proses' : 'Lihat' }}</a></td></tr>@endforeach
            </tbody></table></div>@else<div class="cashier-empty"><i class="bi bi-check-circle text-success me-1"></i>Tidak ada nota yang menunggu pembayaran.</div>@endif
        </section>
        <section class="cashier-section" aria-labelledby="draftHeading">
            <div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><h6 id="draftHeading">Keranjang Belum Selesai</h6>@if(auth()->user()->canAccessRoute('sale.sales.create'))<a href="{{ route('sale.sales.create') }}" class="small text-decoration-none">Buka Draft <i class="bi bi-arrow-right"></i></a>@endif</div>
            @forelse($cashierDrafts as $draft)
            @php $items=collect($draft->payload['items']??[]); $count=$items->pluck('product_id')->filter()->unique()->count(); $total=$items->sum(fn($item)=>(float)($item['quantity']??0)*(float)($item['unit_price']??0)); @endphp
            <div class="cashier-line"><div><div class="fw-semibold text-break">{{ ($draft->payload['customer_name']??'') ?: 'Pelanggan Umum' }}</div><div class="small text-muted">{{ $count }} jenis barang · Rp {{ number_format($total,0,',','.') }} · {{ $draft->updated_at->timezone('Asia/Jakarta')->format('d/m H:i') }}</div></div>@if(auth()->user()->canAccessRoute('sale.sales.create'))<a href="{{ route('sale.sales.create',['draft'=>$draft->id]) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-clockwise me-1"></i>Lanjutkan</a>@endif</div>
            @empty<div class="cashier-empty">Tidak ada draft keranjang yang tersinkron.</div>@endforelse
        </section>
        <section class="cashier-section mt-3" aria-labelledby="recentHeading"><div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><h6 id="recentHeading">Nota Lunas Terakhir</h6><a href="{{ route('sale.sales.index',['status'=>'paid']) }}" class="small text-decoration-none">Lihat Semua <i class="bi bi-arrow-right"></i></a></div>
            @forelse($cashierRecentSales as $sale)<div class="cashier-line"><div><a href="{{ route('sale.sales.show',$sale) }}" class="fw-semibold text-decoration-none">{{ $sale->sale_number }}</a><div class="small text-muted">{{ $sale->sale_date->format('d/m/Y') }} · {{ $sale->transaction_time_label }} · {{ $sale->customer_name ?: 'Pelanggan Umum' }} · {{ ucfirst($sale->payment_method) }}</div></div><strong class="text-success text-nowrap">Rp {{ number_format($sale->total_amount,0,',','.') }}</strong></div>@empty<div class="cashier-empty">Belum ada nota lunas.</div>@endforelse
        </section>
    </div>
    <div class="col-xl-4">
    @php $statusTotal=array_sum($cashierStatusCounts); $paidEnd=$statusTotal ? $cashierStatusCounts['paid']/$statusTotal*100 : 0; $pendingEnd=$statusTotal ? ($cashierStatusCounts['paid']+$cashierStatusCounts['pending'])/$statusTotal*100 : 0; @endphp
    <section class="cashier-section mb-3" aria-labelledby="statusHeading">
        <div class="d-flex flex-wrap justify-content-between gap-2"><h6 id="statusHeading">Status Nota Hari Ini</h6><a href="{{ route('sale.sales.index',['from'=>now('Asia/Jakarta')->toDateString(),'to'=>now('Asia/Jakarta')->toDateString()]) }}" class="small text-decoration-none" title="Lihat nota hari ini"><i class="bi bi-arrow-up-right"></i></a></div>
        <div class="cashier-chart-summary"><div class="cashier-donut" data-donut data-donut-unit="nota" style="--paid-end:{{ $paidEnd }}%;--pending-end:{{ $pendingEnd }}%;{{ $statusTotal === 0 ? 'background:#e8edeb;' : '' }}" role="img" aria-label="Status nota hari ini: {{ $cashierStatusCounts['paid'] }} lunas, {{ $cashierStatusCounts['pending'] }} menunggu pembayaran, {{ $cashierStatusCounts['cancelled'] }} dibatalkan.">
            <div class="cashier-donut-center" aria-hidden="true"><strong>{{ $statusTotal }}</strong><span class="small text-muted">nota hari ini</span></div>
        </div>
        <div>@foreach([['paid','Lunas','#1f7a5a'],['pending','Menunggu Pembayaran','#e7b548'],['cancelled','Dibatalkan','#c84b45']] as [$key,$label,$color])
        <button type="button" class="cashier-chart-legend-row" data-donut-label="{{ $label }}" data-donut-count="{{ $cashierStatusCounts[$key] }}"><span class="status-swatch" style="background:{{ $color }}" aria-hidden="true"></span><span>{{ $label }}</span><span class="text-end"><strong>{{ $cashierStatusCounts[$key] }}</strong><small class="text-muted">{{ $statusTotal ? number_format($cashierStatusCounts[$key]/$statusTotal*100,1,',','.') : '0' }}%</small></span></button>
        @endforeach
        </div></div>
        @if($statusTotal === 0)<div class="small text-muted mt-2">Belum ada nota hari ini.</div>@endif
        @if($cashierExpiredPendingCount > 0)<div class="small text-muted mt-2">Nota pending tetap terhitung sampai dibayar atau dibatalkan.</div>@endif
    </section>
    <section class="cashier-section" aria-labelledby="paymentHeading"><h6 id="paymentHeading">Penjualan Lunas per Pembayaran</h6><div class="small text-muted mt-1">Nota hari ini yang Anda layani</div>
        <div class="cashier-line"><span><i class="bi bi-cash text-success me-2"></i>Tunai</span><strong>Rp {{ number_format($cashierCashToday,0,',','.') }}</strong></div>
        <div class="cashier-line"><span><i class="bi bi-bank text-primary me-2"></i>Transfer</span><strong>Rp {{ number_format($cashierTransferToday,0,',','.') }}</strong></div>
        <div class="cashier-line border-0"><strong>Total</strong><strong>Rp {{ number_format($cashierSalesToday,0,',','.') }}</strong></div>
    </section></div>
</div>
</div>
@endsection
@push('scripts')
<script src="{{ asset('js/dashboard-donuts.js') }}?v=1"></script>
<script>
const cashierTopbar = document.getElementById('topbar');
new ResizeObserver(() => {
    document.querySelector('.cashier-dashboard').style.setProperty('--cashier-topbar-height', `${cashierTopbar.offsetHeight}px`);
}).observe(cashierTopbar);
</script>
@endpush
