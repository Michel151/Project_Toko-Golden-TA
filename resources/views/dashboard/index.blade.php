@extends('layouts.app')
@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@push('styles')
<style>
    .dashboard-hero {
        background: linear-gradient(125deg, #172039 0%, #28334f 65%, #8b6808 140%);
        border-radius: 16px;
        color: #fff;
        overflow: hidden;
        position: relative;
    }
    .dashboard-hero::after {
        content: '';
        position: absolute;
        width: 230px; height: 230px;
        right: -65px; top: -105px;
        border-radius: 50%;
        background: rgba(200,150,12,.18);
    }
    .insight-card { border: 0; border-radius: 14px; box-shadow: 0 3px 14px rgba(25,35,55,.07); }
    .metric-icon { width: 46px; height: 46px; border-radius: 12px; display: grid; place-items: center; font-size: 1.25rem; flex: 0 0 auto; }
    .metric-value { font-size: 1.25rem; font-weight: 750; line-height: 1.2; }
    .trend { font-size: .72rem; font-weight: 600; }
    .section-title { font-size: .95rem; font-weight: 700; color: #27324a; }
    .chart-wrap { height: 270px; position: relative; }
    .rank-number { width: 28px; height: 28px; border-radius: 8px; display: grid; place-items: center; background: #f1f3f8; color: #68738b; font-weight: 700; font-size: .75rem; }
    .insight-list > * + * { border-top: 1px solid #edf0f5; }
    .recent-transactions-scroll {
        max-height: 360px;
        overflow-y: auto;
        scrollbar-gutter: stable;
    }
    .stock-alert { border-left: 3px solid var(--bs-warning); background: #fafbfc; border-radius: 0 8px 8px 0; }
    .quick-pill { background: rgba(255,255,255,.11); border: 1px solid rgba(255,255,255,.14); color: #fff; border-radius: 10px; }
    .quick-pill:hover { background: rgba(255,255,255,.18); color: #fff; }
</style>
@endpush

@section('content')
<div class="dashboard-hero p-4 mb-4">
    <div class="position-relative" style="z-index:1">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <div class="small text-white-50 mb-1">{{ now()->translatedFormat('l, d F Y') }}</div>
                <h4 class="fw-bold mb-1">Selamat datang, {{ auth()->user()->name }}</h4>
                <div class="text-white-50">Ringkasan kondisi  Toko Golden hari ini.</div>
            </div>
            <div class="d-flex gap-2">
                @if(auth()->user()->isOwner() || auth()->user()->isKasir())
                <a href="{{ route('sale.sales.create') }}" class="btn btn-sm quick-pill px-3"><i class="bi bi-plus-circle me-1"></i> Penjualan</a>
                @endif
                @if(auth()->user()->isOwner())
                <a href="{{ route('purchase.orders.create') }}" class="btn btn-sm quick-pill px-3"><i class="bi bi-cart-plus me-1"></i> Buat PO</a>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card insight-card h-100"><div class="card-body d-flex gap-3">
            <div class="metric-icon bg-success bg-opacity-10 text-success"><i class="bi bi-cash-coin"></i></div>
            <div class="min-w-0"><div class="text-muted small mb-1">Penjualan Hari Ini</div><div class="metric-value">Rp {{ number_format($salesToday, 0, ',', '.') }}</div>
                <div class="trend mt-2 {{ $dailyGrowth >= 0 ? 'text-success' : 'text-danger' }}"><i class="bi bi-arrow-{{ $dailyGrowth >= 0 ? 'up' : 'down' }}-right"></i> {{ number_format(abs($dailyGrowth), 1, ',', '.') }}% dari kemarin</div>
            </div>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card insight-card h-100"><div class="card-body d-flex gap-3">
            <div class="metric-icon bg-primary bg-opacity-10 text-primary"><i class="bi bi-graph-up-arrow"></i></div>
            <div><div class="text-muted small mb-1">Penjualan Bulan Ini</div><div class="metric-value">Rp {{ number_format($salesMonth, 0, ',', '.') }}</div>
                <div class="trend mt-2 {{ $monthlyGrowth >= 0 ? 'text-success' : 'text-danger' }}">{{ $salesMonthCount }} transaksi · {{ $monthlyGrowth >= 0 ? '+' : '-' }}{{ number_format(abs($monthlyGrowth), 1, ',', '.') }}%</div>
            </div>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card insight-card h-100"><div class="card-body d-flex gap-3">
            <div class="metric-icon bg-warning bg-opacity-10 text-warning"><i class="bi bi-boxes"></i></div>
            <div><div class="text-muted small mb-1">Nilai Persediaan</div><div class="metric-value">Rp {{ number_format($inventoryValue, 0, ',', '.') }}</div>
                <div class="trend mt-2 text-muted">{{ $totalProducts }} jenis barang · {{ $outOfStockCount }} habis</div>
            </div>
        </div></div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card insight-card h-100"><div class="card-body d-flex gap-3">
            <div class="metric-icon bg-info bg-opacity-10 text-info"><i class="bi bi-receipt"></i></div>
            <div><div class="text-muted small mb-1">Rata-rata Transaksi</div><div class="metric-value">Rp {{ number_format($averageTransaction, 0, ',', '.') }}</div>
                <div class="trend mt-2 text-muted">{{ $pendingPO }} PO Â· {{ $expiringSoonCount }} batch hampir expired</div>
            </div>
        </div></div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-8">
        <div class="card insight-card h-100">
            <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center pt-3 px-3">
                <div><div class="section-title">Tren Penjualan 7 Hari</div><small class="text-muted">Pergerakan omzet harian</small></div>
                <span class="badge bg-primary bg-opacity-10 text-primary">Rp {{ number_format($salesChart->sum('total'), 0, ',', '.') }}</span>
            </div>
            <div class="card-body"><div class="chart-wrap"><canvas id="salesTrendChart"></canvas></div></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card insight-card h-100">
            <div class="card-header bg-white border-0 pt-3 px-3"><div class="section-title">Metode Pembayaran</div><small class="text-muted">Komposisi transaksi bulan ini</small></div>
            <div class="card-body"><div class="chart-wrap"><canvas id="paymentChart"></canvas></div></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card insight-card h-100">
            <div class="card-header bg-white border-0 pt-3"><div class="section-title"><i class="bi bi-trophy text-warning me-2"></i>Produk Terlaris</div><small class="text-muted">Berdasarkan omzet 30 hari terakhir</small></div>
            <div class="card-body pt-2 insight-list">
                @forelse($topProducts as $index => $item)
                <div class="d-flex align-items-center gap-3 py-2">
                    <div class="rank-number">{{ $index + 1 }}</div><div class="flex-grow-1 min-w-0"><div class="fw-semibold text-truncate">{{ $item->name }}</div><small class="text-muted">Rp {{ number_format($item->revenue, 0, ',', '.') }}</small></div>
                    @if($index === 0)<i class="bi bi-star-fill text-warning"></i>@endif
                </div>
                @empty
                <div class="text-center text-muted py-4"><i class="bi bi-bar-chart d-block fs-2 mb-2"></i>Belum ada data penjualan</div>
                @endforelse
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card insight-card h-100">
            <div class="card-header bg-white border-0 pt-3"><div class="section-title"><i class="bi bi-geo-alt text-primary me-2"></i>Nilai Stok per Lokasi</div><small class="text-muted">Estimasi berdasarkan harga beli</small></div>
            <div class="card-body pt-2 insight-list">
                @php $maxLocationValue = max(1, (float) ($locationValues->max('value') ?? 1)); @endphp
                @foreach($locationValues as $location)
                <div class="py-3"><div class="d-flex justify-content-between mb-2"><span class="fw-semibold">{{ $location->name }}</span><span class="small">Rp {{ number_format($location->value, 0, ',', '.') }}</span></div>
                    <div class="progress" style="height:6px"><div class="progress-bar" style="width:{{ ((float)$location->value / $maxLocationValue) * 100 }}%; background:#c8960c"></div></div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card insight-card h-100">
            <div class="card-header bg-white border-0 pt-3"><div class="section-title"><i class="bi bi-clock-history text-success me-2"></i>Transaksi Terbaru</div><small class="text-muted">Aktivitas penjualan terkini</small></div>
            <div class="card-body pt-2 insight-list recent-transactions-scroll">
                @forelse($recentSales as $sale)
                <div class="d-flex justify-content-between align-items-center gap-2 py-2"><div class="min-w-0"><div class="fw-semibold text-truncate">{{ $sale->sale_number }}</div><small class="text-muted">{{ $sale->sale_date->format('d/m/Y') }} · {{ $sale->createdBy->name ?? '-' }}</small></div><div class="fw-semibold text-success text-nowrap">Rp {{ number_format($sale->total_amount, 0, ',', '.') }}</div></div>
                @empty
                <div class="text-center text-muted py-4">Belum ada transaksi</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

<div class="row g-3" id="notifications">
    <div class="col-lg-5">
        <div class="card insight-card h-100"><div class="card-header bg-white border-0 pt-3 d-flex justify-content-between"><div class="section-title"><i class="bi bi-bell-fill text-warning me-2"></i>Perlu Perhatian</div>@if(count($notifications))<span class="badge bg-danger">{{ count($notifications) }}</span>@endif</div>
            <div class="card-body pt-2" style="max-height:360px;overflow:auto">
                @forelse($notifications as $notif)
                <div class="stock-alert p-3 mb-2"><div class="d-flex justify-content-between"><div><div class="fw-semibold">{{ $notif['product_name'] }}</div><small class="text-muted">{{ $notif['location_name'] }}</small></div><span class="badge bg-{{ $notif['type'] === 'order' ? 'danger' : 'warning text-dark' }} align-self-start">{{ $notif['type'] === 'order' ? 'Order' : 'Transfer' }}</span></div><div class="small mt-2">{{ $notif['message'] }}</div></div>
                @empty
                <div class="text-center text-muted py-4"><i class="bi bi-shield-check fs-2 d-block text-success mb-2"></i>Semua stok dalam kondisi aman</div>
                @endforelse
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card insight-card h-100"><div class="card-header bg-white border-0 pt-3 d-flex justify-content-between"><div class="section-title"><i class="bi bi-exclamation-triangle-fill text-danger me-2"></i>Stok Kritis</div>@if(auth()->user()->isOwner())<a href="{{ route('report.stock') }}" class="small text-decoration-none">Lihat laporan <i class="bi bi-arrow-right"></i></a>@endif</div>
            <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Barang</th><th class="text-end">Stok</th><th class="text-end">Minimum</th><th></th></tr></thead><tbody>
                @forelse($lowStockProducts as $product)
                @php $total = (float)$product->stocks->sum('quantity'); @endphp
                <tr><td><div class="fw-semibold">{{ $product->name }}</div><small class="text-muted">{{ $product->category->name ?? '-' }}</small></td><td class="text-end text-danger fw-semibold">{{ $product->displayStock($total) }}</td><td class="text-end text-muted">{{ $product->displayStock((float)$product->min_stock_total) }}</td><td>@if(auth()->user()->isOwner())<a href="{{ route('purchase.orders.create') }}" class="btn btn-sm btn-outline-warning">Order</a>@endif</td></tr>
                @empty
                <tr><td colspan="4" class="text-center text-muted py-4"><i class="bi bi-check-circle text-success me-1"></i> Semua stok aman</td></tr>
                @endforelse
            </tbody></table></div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-12">
        <div class="card insight-card">
            <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between">
                <div class="section-title"><i class="bi bi-calendar2-week text-warning me-2"></i>Batch Kadaluarsa</div>
                <span class="badge bg-danger">{{ $expiredBatchCount }} sudah kadaluarsa</span>
            </div>
            <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Barang</th><th>Lokasi</th><th class="text-end">Sisa</th><th>Kadaluarsa</th><th class="text-center">Status</th></tr></thead><tbody>
                @forelse($expiringBatches as $batch)
                @php $expired=$batch->expired_date->lt(today()); @endphp
                <tr><td class="fw-semibold">{{ $batch->product->name }}</td><td>{{ $batch->location->name }}</td><td class="text-end">{{ $batch->product->displayStock((float)$batch->quantity_remaining) }}</td><td>{{ $batch->expired_date->format('d/m/Y') }}</td><td class="text-center"><span class="badge bg-{{ $expired ? 'danger' : 'warning text-dark' }}">{{ $expired ? 'Kadaluarsa' : 'Hampir Kadaluarsa' }}</span></td></tr>
                @empty
                <tr><td colspan="5" class="text-center text-muted py-4"><i class="bi bi-check-circle text-success me-1"></i> Tidak ada batch yang perlu ditindaklanjuti.</td></tr>
                @endforelse
            </tbody></table></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
    const currency = value => 'Rp ' + new Intl.NumberFormat('id-ID').format(value);
    const chartGrid = { color: 'rgba(120,130,150,.12)', drawBorder: false };

    new Chart(document.getElementById('salesTrendChart'), {
        type: 'line',
        data: {
            labels: @json($salesChart->pluck('label')),
            datasets: [{ data: @json($salesChart->pluck('total')), borderColor: '#c8960c', backgroundColor: 'rgba(200,150,12,.12)', fill: true, tension: .38, pointRadius: 4, pointBackgroundColor: '#c8960c', pointBorderColor: '#fff', pointBorderWidth: 2 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: context => currency(context.raw) } } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: chartGrid, ticks: { callback: value => value >= 1000000 ? 'Rp ' + (value / 1000000) + ' jt' : 'Rp ' + (value / 1000) + ' rb' } } } }
    });

    new Chart(document.getElementById('paymentChart'), {
        type: 'doughnut',
        data: { labels: ['Tunai', 'Transfer'], datasets: [{ data: [{{ (int)($paymentMethods['tunai'] ?? 0) }}, {{ (int)($paymentMethods['transfer'] ?? 0) }}], backgroundColor: ['#c8960c', '#3b82f6'], borderWidth: 0, hoverOffset: 5 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20 } } } }
    });
</script>
@endpush
