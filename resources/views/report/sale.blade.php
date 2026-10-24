@extends('layouts.app')
@section('title', 'Laporan Penjualan')
@section('page-title', 'Laporan Penjualan')

@section('content')
<div class="report-heading d-flex justify-content-between align-items-center mb-3"><div class="d-flex align-items-center gap-3"><div class="report-icon"><i class="bi bi-graph-up-arrow"></i></div><div><h5 class="fw-bold mb-1">Laporan Penjualan</h5><div class="text-white-50 small">Analisis transaksi, pembayaran, dan produk terlaris</div></div></div><span class="badge bg-success">{{ $totalTrx }} Transaksi</span></div>
<div class="report-toolbar d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
    <form class="d-flex gap-2 align-items-end" method="GET">
        @include('components.date-range',['prefix'=>'saleReport','fromValue'=>$from,'toValue'=>$to])
        <button class="btn btn-sm btn-primary px-3"><i class="bi bi-funnel me-1"></i>Terapkan</button>
    </form>
    <div class="btn-group btn-group-sm">
        <a href="{{ route('report.sale.pdf', request()->all()) }}" class="btn btn-outline-danger" target="_blank"><i class="bi bi-file-pdf me-1"></i>PDF</a>
        <a href="{{ route('report.sale.excel', request()->all()) }}" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    </div>
</div>

{{-- Summary cards --}}
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card report-stat h-100" style="--stat-color:#198754"><div class="card-body d-flex align-items-center gap-3"><div class="stat-symbol"><i class="bi bi-cash-stack"></i></div><div>
            <div class="text-muted small">Total Penjualan</div>
            <div class="fw-bold">Rp {{ number_format($totalAmount, 0, ',', '.') }}</div>
        </div></div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card report-stat h-100" style="--stat-color:#0d6efd"><div class="card-body d-flex align-items-center gap-3"><div class="stat-symbol"><i class="bi bi-receipt"></i></div><div>
            <div class="text-muted small">Jumlah Transaksi</div>
            <div class="fw-bold">{{ $totalTrx }}</div>
        </div></div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card report-stat h-100" style="--stat-color:#c8960c"><div class="card-body d-flex align-items-center gap-3"><div class="stat-symbol"><i class="bi bi-cash"></i></div><div>
            <div class="text-muted small">Tunai</div>
            <div class="fw-bold text-success">Rp {{ number_format($totalTunai, 0, ',', '.') }}</div>
        </div></div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card report-stat h-100" style="--stat-color:#0dcaf0"><div class="card-body d-flex align-items-center gap-3"><div class="stat-symbol"><i class="bi bi-bank"></i></div><div>
            <div class="text-muted small">Transfer</div>
            <div class="fw-bold text-info">Rp {{ number_format($totalTransfer, 0, ',', '.') }}</div>
        </div></div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-8">
        <div class="card table-card">
            <div class="card-header bg-white border-0 pt-3"><h6 class="fw-bold mb-0">Daftar Transaksi</h6></div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover report-table app-mobile-records mb-0" data-mobile-records>
                    <thead><tr><th>No. Invoice</th><th>Tanggal</th><th>Pelanggan / Kasir</th><th class="text-center">Bayar</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                        @forelse($sales as $sale)
                        <tr>
                            <td><a href="{{ route('sale.sales.show', $sale) }}" class="text-decoration-none">{{ $sale->sale_number }}</a></td>
                            <td>{{ $sale->sale_date->format('d/m/Y') }}</td>
                            <td>{{ $sale->customer_name ?: 'Umum' }}<div class="small text-muted">{{ $sale->createdBy?->name ?? '-' }}</div></td>
                            <td class="text-center"><span class="badge bg-{{ $sale->payment_method === 'tunai' ? 'success' : 'info' }}">{{ ucfirst($sale->payment_method) }}</span></td>
                            <td class="text-end">Rp {{ number_format($sale->total_amount, 0, ',', '.') }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="report-empty"><i class="bi bi-inbox fs-2 d-block mb-2"></i>Tidak ada transaksi pada periode ini</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card table-card">
            <div class="card-header bg-white border-0 pt-3"><h6 class="fw-bold mb-0">Produk Terlaris</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>#</th><th>Barang</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                        @foreach($topProducts as $i => $tp)
                        <tr>
                            <td class="text-muted">{{ $i + 1 }}</td>
                            <td class="small">{{ $tp->product->name ?? '-' }}</td>
                            <td class="text-end small">Rp {{ number_format($tp->total_amount, 0, ',', '.') }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
