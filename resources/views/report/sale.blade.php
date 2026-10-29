@extends('layouts.app')
@section('title', 'Laporan Penjualan')
@section('page-title', 'Laporan Penjualan')
@push('styles')
<style>
.sale-report-tabs { gap:.5rem; }
#saleReportTabs { scroll-margin-top:80px; }
.sale-report-tabs .nav-link { color:var(--app-muted);background:#fff;border:1px solid var(--app-border); }
</style>
@endpush

@section('content')
<div class="report-heading d-flex justify-content-between align-items-center mb-3"><div class="d-flex align-items-center gap-3"><div class="report-icon"><i class="bi bi-graph-up-arrow"></i></div><div><h5 class="fw-bold mb-1">Laporan Penjualan</h5><div class="text-white-50 small">Analisis transaksi, pembayaran, dan produk terlaris</div></div></div><span class="badge bg-success">{{ $totalTrx }} Transaksi</span></div>
<div class="report-toolbar d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
    <form class="app-unified-filter d-flex flex-wrap gap-2 align-items-end" method="GET">
        <input type="hidden" name="tab" id="saleReportActiveTab" value="{{ $activeTab }}">
        <input type="hidden" name="products_sort" value="{{ $productsSort }}"><input type="hidden" name="products_unit" value="{{ $productsUnit }}">
        @include('components.date-range',['prefix'=>'saleReport','fromValue'=>$from,'toValue'=>$to])
        <div><label for="saleReportCategory" class="d-block">Kategori</label><select id="saleReportCategory" name="category_id" class="form-select form-select-sm"><option value="">Semua Kategori</option>@foreach($categories as $option)<option value="{{ $option->id }}" @selected($categoryId === $option->id)>{{ $option->name }}</option>@endforeach</select></div>
        <div><label for="saleReportPerPage" class="d-block">Per Halaman</label><select id="saleReportPerPage" name="per_page" class="form-select form-select-sm">
            @foreach([25, 50, 100] as $size)<option value="{{ $size }}" @selected($sales->perPage() === $size)>{{ $size }}</option>@endforeach
        </select></div>
        <button class="btn btn-sm btn-primary px-3"><i class="bi bi-funnel me-1"></i>Terapkan</button>
    <a href="{{ route('report.sale') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset</a></form>
    <div class="btn-group btn-group-sm">
        <a href="{{ route('report.sale.pdf', request()->all()) }}" class="btn btn-outline-danger" target="_blank"><i class="bi bi-file-pdf me-1"></i>PDF</a>
        <a href="{{ route('report.sale.excel', request()->all()) }}" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    </div>
</div>

{{-- Summary cards --}}
@if($category)<div class="small text-muted mb-3">Kategori: <strong>{{ $category->name }}</strong>. Nominal dan laba hanya mencakup barang dalam kategori terpilih, termasuk pada nota campuran.</div>@endif
<div class="row g-3 mb-3">
    <div class="col-6 col-md-4 col-xl">
        <div class="card report-stat h-100" style="--stat-color:#198754"><div class="card-body d-flex align-items-center gap-3"><div class="stat-symbol"><i class="bi bi-cash-stack"></i></div><div>
            <div class="text-muted small">Total Penjualan</div>
            <div class="report-kpi-value fw-bold">Rp {{ number_format($totalAmount, 0, ',', '.') }}</div>
        </div></div></div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="card report-stat h-100" style="--stat-color:#0d6efd"><div class="card-body d-flex align-items-center gap-3"><div class="stat-symbol"><i class="bi bi-calculator"></i></div><div>
            <div class="text-muted small">Laba Kotor</div>
            <div class="report-kpi-value fw-bold {{ $grossProfit !== null && $grossProfit < 0 ? 'text-danger' : '' }}">{{ $grossProfit === null ? 'Belum lengkap' : 'Rp '.number_format($grossProfit, 0, ',', '.') }}</div>
            <div class="small text-muted">{{ $incompleteCostCount ? $incompleteCostCount.' transaksi dengan HPP belum lengkap' : 'Penjualan lunas − HPP tercatat' }}</div>
            <div class="small text-muted">Belum dikurangi biaya operasional</div>
        </div></div></div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="card report-stat h-100" style="--stat-color:#0d6efd"><div class="card-body d-flex align-items-center gap-3"><div class="stat-symbol"><i class="bi bi-receipt"></i></div><div>
            <div class="text-muted small">Jumlah Transaksi</div>
            <div class="report-kpi-value fw-bold">{{ $totalTrx }}</div>
        </div></div></div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="card report-stat h-100" style="--stat-color:#198754"><div class="card-body d-flex align-items-center gap-3"><div class="stat-symbol"><i class="bi bi-cash"></i></div><div>
            <div class="text-muted small">Tunai</div>
            <div class="report-kpi-value fw-bold text-success">Rp {{ number_format($totalTunai, 0, ',', '.') }}</div>
        </div></div></div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="card report-stat h-100" style="--stat-color:#0dcaf0"><div class="card-body d-flex align-items-center gap-3"><div class="stat-symbol"><i class="bi bi-bank"></i></div><div>
            <div class="text-muted small">Transfer</div>
            <div class="report-kpi-value fw-bold">Rp {{ number_format($totalTransfer, 0, ',', '.') }}</div>
        </div></div></div>
    </div>
</div>

<div class="nav nav-pills sale-report-tabs mb-3" id="saleReportTabs" role="tablist" aria-label="Rincian laporan penjualan">
    <button class="nav-link {{ $activeTab === 'transactions' ? 'active' : '' }}" id="saleTransactionsTab" data-bs-toggle="pill" data-bs-target="#saleTransactionsPane" data-report-tab="transactions" type="button" role="tab" aria-controls="saleTransactionsPane" aria-selected="{{ $activeTab === 'transactions' ? 'true' : 'false' }}"><i class="bi bi-receipt me-1" aria-hidden="true"></i>Daftar Transaksi</button>
    <button class="nav-link {{ $activeTab === 'products' ? 'active' : '' }}" id="saleProductsTab" data-bs-toggle="pill" data-bs-target="#saleProductsPane" data-report-tab="products" type="button" role="tab" aria-controls="saleProductsPane" aria-selected="{{ $activeTab === 'products' ? 'true' : 'false' }}"><i class="bi bi-trophy me-1" aria-hidden="true"></i>Produk Terlaris</button>
    <button class="nav-link {{ $activeTab === 'margins' ? 'active' : '' }}" id="saleMarginsTab" data-bs-toggle="pill" data-bs-target="#saleMarginsPane" data-report-tab="margins" type="button" role="tab" aria-controls="saleMarginsPane" aria-selected="{{ $activeTab === 'margins' ? 'true' : 'false' }}"><i class="bi bi-calculator me-1" aria-hidden="true"></i>Margin per Produk</button>
</div>
<div class="tab-content sale-report-overview">
    <div class="tab-pane {{ $activeTab === 'transactions' ? 'show active' : '' }}" id="saleTransactionsPane" role="tabpanel" aria-labelledby="saleTransactionsTab" tabindex="0">
        <div class="card table-card">
            <div class="card-header bg-white border-0 pt-3"><h6 class="fw-bold mb-0">Daftar Transaksi</h6></div>
            <div class="card-body p-0 table-responsive" tabindex="0" role="region" aria-label="Daftar transaksi penjualan">
                <table id="saleTransactionsTable" class="table table-hover report-table app-mobile-records mb-0" data-mobile-records>
                    <thead><tr><th>No. Invoice</th><th>Tanggal</th><th>Pelanggan / Kasir</th><th class="text-center">Bayar</th><th class="text-end">{{ $category ? 'Total Kategori' : 'Total' }}</th></tr></thead>
                    <tbody>
                        @forelse($sales as $sale)
                        <tr>
                            <td><a href="{{ route('sale.sales.show', $sale) }}" class="text-decoration-none">{{ $sale->sale_number }}</a></td>
                            <td>{{ $sale->sale_date->format('d/m/Y') }}</td>
                            <td>{{ $sale->customer_name ?: 'Umum' }}<div class="small text-muted">{{ $sale->createdBy?->name ?? '-' }}</div></td>
                            <td class="text-center"><span class="badge bg-{{ $sale->payment_method === 'tunai' ? 'success' : 'info' }}">{{ ucfirst($sale->payment_method) }}</span></td>
                            <td class="text-end">Rp {{ number_format($sale->report_total_amount, 0, ',', '.') }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="report-empty">@include('components.report-empty', ['message'=>'Tidak ada transaksi pada periode ini'])</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white">{{ $sales->links() }}</div>
        </div>
    </div>
    <div class="tab-pane {{ $activeTab === 'products' ? 'show active' : '' }}" id="saleProductsPane" role="tabpanel" aria-labelledby="saleProductsTab" tabindex="0">
        <div class="card table-card">
            <div class="card-header bg-white border-0 pt-3 d-flex flex-wrap align-items-end justify-content-between gap-3">
                <div><h6 class="fw-bold mb-1">Produk Terlaris</h6><div class="small text-muted">Semua barang terjual pada periode terpilih. Jumlah ditampilkan dalam multi satuan masing-masing barang.</div></div>
                <form method="GET" action="{{ route('report.sale') }}" class="app-unified-filter d-flex flex-wrap align-items-end gap-2">
                    <input type="hidden" name="from" value="{{ $from }}"><input type="hidden" name="to" value="{{ $to }}">
                    <input type="hidden" name="per_page" value="{{ $sales->perPage() }}"><input type="hidden" name="page" value="{{ $sales->currentPage() }}"><input type="hidden" name="tab" value="products">
                    <input type="hidden" name="margins_page" value="{{ $productMargins->currentPage() }}">
                    <input type="hidden" name="category_id" value="{{ $categoryId }}">
                    <div><label for="saleProductsSort" class="form-label small mb-1">Urutkan Berdasarkan</label><select id="saleProductsSort" name="products_sort" class="form-select form-select-sm"><option value="revenue" @selected($productsSort === 'revenue')>Omzet Tertinggi</option><option value="quantity" @selected($productsSort === 'quantity')>Jumlah Kemasan</option></select></div>
                    <div id="saleProductsUnitField" @if($productsSort !== 'quantity') hidden @endif><label for="saleProductsUnit" class="form-label small mb-1">Satuan Jual Utama</label><select id="saleProductsUnit" name="products_unit" class="form-select form-select-sm"><option value="">Semua satuan</option>@foreach($rankingUnits as $unit)<option value="{{ $unit['id'] }}" @selected((string)$productsUnit === (string)$unit['id'])>{{ $unit['name'] }}</option>@endforeach</select></div>
                    <button class="btn btn-sm btn-primary">Terapkan</button>
                <a href="{{ route('report.sale', ['from'=>$from,'to'=>$to,'category_id'=>$categoryId,'tab'=>'products','per_page'=>$sales->perPage()]) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset</a></form>
            </div>
            <div class="card-body p-0 table-responsive" tabindex="0" role="region" aria-label="Produk terlaris">
                <table id="saleTopProductsTable" class="table table-hover report-table app-mobile-records mb-0" data-mobile-records>
                    <thead><tr><th>Peringkat</th><th>Barang</th><th class="text-end">Jumlah Terjual</th><th class="text-end">Total Penjualan</th></tr></thead>
                    <tbody>
                        @forelse($topProducts as $i => $tp)
                        <tr>
                            <td class="text-muted">{{ $topProducts->firstItem() + $loop->index }}</td>
                            <td class="small">{{ $tp->product->name ?? '-' }}</td>
                            <td class="text-end small">{{ $tp->display_quantity }}</td>
                            <td class="text-end small">Rp {{ number_format($tp->total_amount, 0, ',', '.') }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="4" class="report-empty">@include('components.report-empty', ['message'=>'Tidak ada penjualan produk pada periode ini.'])</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white">{{ $topProducts->links() }}</div>
        </div>
    </div>
    <div class="tab-pane {{ $activeTab === 'margins' ? 'show active' : '' }}" id="saleMarginsPane" role="tabpanel" aria-labelledby="saleMarginsTab" tabindex="0">
<section class="card table-card" aria-labelledby="productMarginHeading">
    <div class="card-header bg-white border-0 pt-3">
        <h6 id="productMarginHeading" class="fw-bold mb-1">Margin per Produk</h6>
        <div class="small text-muted">Penjualan lunas pada periode terpilih. Margin = laba kotor ÷ omzet × 100%, sebelum biaya operasional.</div>
    </div>
    <div class="card-body p-0 table-responsive" tabindex="0" role="region" aria-label="Margin per produk">
        <table id="productMarginTable" class="table table-hover report-table app-mobile-records mb-0" data-mobile-records>
            <thead><tr><th>Barang</th><th class="text-end">Omzet</th><th class="text-end">HPP</th><th class="text-end">Laba Kotor</th><th class="text-end">Margin</th></tr></thead>
            <tbody>
                @forelse($productMargins as $margin)
                <tr>
                    <td class="fw-semibold">{{ $margin->name }}</td>
                    <td class="text-end">Rp {{ number_format($margin->revenue, 0, ',', '.') }}</td>
                    <td class="text-end">{{ $margin->cost === null ? 'Belum lengkap' : 'Rp '.number_format($margin->cost, 0, ',', '.') }}</td>
                    <td class="text-end {{ $margin->gross_profit !== null && $margin->gross_profit < 0 ? 'text-danger' : '' }}">{{ $margin->gross_profit === null ? 'Belum lengkap' : 'Rp '.number_format($margin->gross_profit, 0, ',', '.') }}</td>
                    <td class="text-end fw-semibold {{ $margin->margin_percentage !== null && $margin->margin_percentage < 0 ? 'text-danger' : '' }}">{{ $margin->cost === null ? 'Belum lengkap' : ($margin->margin_percentage === null ? '—' : number_format($margin->margin_percentage, 2, ',', '.').'%') }}</td>
                </tr>
                @empty
                <tr><td colspan="5" class="report-empty">@include('components.report-empty', ['message'=>'Tidak ada penjualan produk pada periode ini.'])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white">{{ $productMargins->links() }}</div>
</section>
    </div>
</div>
@push('scripts')
<script>
(() => {
    document.querySelectorAll('#saleReportTabs [data-report-tab]').forEach(button => {
        button.addEventListener('shown.bs.tab', () => {
            const tab = button.dataset.reportTab;
            document.getElementById('saleReportActiveTab').value = tab;
            const url = new URL(location.href);
            url.searchParams.set('tab', tab);
            history.replaceState(null, '', url);
            document.querySelectorAll('.sale-report-overview .pagination a').forEach(link => {
                const target = new URL(link.href, location.href);
                target.searchParams.set('tab', tab);
                link.href = target.href;
            });
        });
    });
})();
</script>
@endpush
@endsection

@push('scripts')
<script>
document.getElementById('saleProductsSort').addEventListener('change', function () {
    document.getElementById('saleProductsUnitField').hidden = this.value !== 'quantity';
});
</script>
@endpush
