@extends('layouts.app')
@section('title','Dashboard Kasir')
@section('page-title','Dashboard Kasir')

@push('styles')
<style>
    .cashier-hero{background:linear-gradient(125deg,#172039,#28334f 70%,#8b6808 145%);border-radius:16px;color:#fff;padding:1.4rem;position:relative;overflow:hidden}.cashier-hero::after{content:'';position:absolute;width:220px;height:220px;border-radius:50%;right:-70px;top:-110px;background:rgba(200,150,12,.18)}
    .cashier-card{border:0;border-radius:14px;box-shadow:0 3px 14px rgba(25,35,55,.07)}.cashier-metric{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;font-size:1.2rem}.cashier-value{font-size:1.25rem;font-weight:750}
    .cashier-chart{height:290px;position:relative}.payment-row{padding:.85rem 0;border-bottom:1px solid #edf0f4}.payment-row:last-child{border-bottom:0}.recent-sale{padding:.8rem 0;border-bottom:1px solid #edf0f4}.recent-sale:last-child{border-bottom:0}
    .quick-sale{background:linear-gradient(135deg,#c8960c,#e8b820);border:0;color:#172039;font-weight:700}.quick-sale:hover{color:#172039;opacity:.92}
</style>
@endpush

@section('content')
<div class="cashier-hero mb-3"><div class="position-relative d-flex flex-wrap justify-content-between align-items-center gap-3" style="z-index:1"><div><div class="small text-white-50 mb-1">{{ now()->translatedFormat('l, d F Y') }}</div><h4 class="fw-bold mb-1">Selamat bekerja, {{ auth()->user()->name }}</h4><div class="small text-white-50">Pantau transaksi Anda dan layani pelanggan dengan cepat.</div></div><a href="{{ route('sale.sales.create') }}" class="btn quick-sale px-4"><i class="bi bi-plus-circle me-1"></i>Transaksi Baru</a></div></div>

<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><div class="card cashier-card h-100"><div class="card-body d-flex gap-3 align-items-center"><div class="cashier-metric bg-success bg-opacity-10 text-success"><i class="bi bi-cash-coin"></i></div><div><div class="text-muted small">Penjualan Saya Hari Ini</div><div class="cashier-value">Rp {{ number_format($cashierSalesToday,0,',','.') }}</div></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card cashier-card h-100"><div class="card-body d-flex gap-3 align-items-center"><div class="cashier-metric bg-primary bg-opacity-10 text-primary"><i class="bi bi-receipt"></i></div><div><div class="text-muted small">Transaksi Hari Ini</div><div class="cashier-value">{{ $cashierTransactionsToday }}</div><small class="text-muted">transaksi</small></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card cashier-card h-100"><div class="card-body d-flex gap-3 align-items-center"><div class="cashier-metric bg-warning bg-opacity-10 text-warning"><i class="bi bi-calculator"></i></div><div><div class="text-muted small">Rata-rata Transaksi</div><div class="cashier-value">Rp {{ number_format($cashierAverageToday,0,',','.') }}</div></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card cashier-card h-100"><div class="card-body d-flex gap-3 align-items-center"><div class="cashier-metric bg-info bg-opacity-10 text-info"><i class="bi bi-calendar3"></i></div><div><div class="text-muted small">Penjualan Bulan Ini</div><div class="cashier-value">Rp {{ number_format($cashierSalesMonth,0,',','.') }}</div><small class="text-muted">{{ $cashierTransactionsMonth }} transaksi</small></div></div></div></div>
</div>

<div class="row g-3">
    <div class="col-xl-8"><div class="card cashier-card h-100"><div class="card-header bg-white border-0 pt-3 d-flex justify-content-between"><div><h6 class="fw-bold mb-1">Tren Penjualan Saya</h6><small class="text-muted">Omzet pribadi tujuh hari terakhir</small></div><span class="badge bg-success bg-opacity-10 text-success align-self-start">Rp {{ number_format($cashierSalesChart->sum('total'),0,',','.') }}</span></div><div class="card-body"><div class="cashier-chart"><canvas id="cashierSalesChart"></canvas></div></div></div></div>
    <div class="col-xl-4"><div class="card cashier-card mb-3"><div class="card-header bg-white border-0 pt-3"><h6 class="fw-bold mb-1">Pembayaran Hari Ini</h6><small class="text-muted">Ringkasan penerimaan berdasarkan metode</small></div><div class="card-body pt-2">
        @php $paymentTotal=max(1,$cashierCashToday+$cashierTransferToday); @endphp
        <div class="payment-row"><div class="d-flex justify-content-between mb-2"><span><i class="bi bi-cash text-success me-2"></i>Tunai</span><strong>Rp {{ number_format($cashierCashToday,0,',','.') }}</strong></div><div class="progress" style="height:6px"><div class="progress-bar bg-success" style="width:{{ ($cashierCashToday/$paymentTotal)*100 }}%"></div></div></div>
        <div class="payment-row"><div class="d-flex justify-content-between mb-2"><span><i class="bi bi-bank text-primary me-2"></i>Transfer</span><strong>Rp {{ number_format($cashierTransferToday,0,',','.') }}</strong></div><div class="progress" style="height:6px"><div class="progress-bar bg-primary" style="width:{{ ($cashierTransferToday/$paymentTotal)*100 }}%"></div></div></div>
    </div></div>
    <div class="card cashier-card"><div class="card-body text-center py-4"><div class="cashier-metric bg-warning bg-opacity-10 text-warning mx-auto mb-2"><i class="bi bi-lightning-charge"></i></div><h6 class="fw-bold">Siap melayani pelanggan?</h6><p class="small text-muted">Buka transaksi baru untuk mulai mencatat penjualan.</p><a href="{{ route('sale.sales.create') }}" class="btn quick-sale btn-sm px-4">Mulai Penjualan</a></div></div>
    </div>
</div>

<div class="card cashier-card mt-3"><div class="card-header bg-white border-0 pt-3 d-flex justify-content-between"><div><h6 class="fw-bold mb-1">Transaksi Terakhir Saya</h6><small class="text-muted">Delapan transaksi terbaru yang Anda layani</small></div><a href="{{ route('sale.sales.index') }}" class="small text-decoration-none">Lihat Semua <i class="bi bi-arrow-right"></i></a></div><div class="card-body pt-1">
@forelse($cashierRecentSales as $sale)<div class="recent-sale d-flex justify-content-between align-items-center gap-3"><div class="d-flex align-items-center gap-3"><div class="cashier-metric bg-light text-secondary" style="width:36px;height:36px"><i class="bi bi-receipt"></i></div><div><a href="{{ route('sale.sales.show',$sale) }}" class="fw-semibold text-decoration-none">{{ $sale->sale_number }}</a><div class="small text-muted">{{ $sale->sale_date->format('d/m/Y') }} · {{ $sale->customer_name??'Pelanggan Umum' }} · {{ ucfirst($sale->payment_method) }}</div></div></div><div class="fw-bold text-success text-nowrap">Rp {{ number_format($sale->total_amount,0,',','.') }}</div></div>@empty<div class="text-center text-muted py-4"><i class="bi bi-inbox fs-2 d-block mb-2"></i>Anda belum memiliki transaksi</div>@endforelse
</div></div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('cashierSalesChart'),{type:'bar',data:{labels:@json($cashierSalesChart->pluck('label')),datasets:[{data:@json($cashierSalesChart->pluck('total')),backgroundColor:'rgba(200,150,12,.72)',borderColor:'#c8960c',borderWidth:1,borderRadius:6,maxBarThickness:44}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>'Rp '+new Intl.NumberFormat('id-ID').format(c.raw)}}},scales:{x:{grid:{display:false}},y:{beginAtZero:true,grid:{color:'rgba(120,130,150,.12)'},ticks:{callback:v=>v>=1000000?'Rp '+v/1000000+' jt':'Rp '+v/1000+' rb'}}}}});
</script>
@endpush
