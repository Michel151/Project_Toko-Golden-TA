@extends('layouts.app')
@section('title', 'Laporan Laba Kotor')
@section('page-title', 'Laporan Laba Kotor')

@section('content')
<div class="report-heading d-flex justify-content-between align-items-center mb-3">
    <div class="d-flex align-items-center gap-3">
        <div class="report-icon"><i class="bi bi-currency-dollar"></i></div>
        <div><h5 class="fw-bold mb-1">Laporan Laba Kotor</h5><div class="text-white-50 small">Analisis omzet, harga pokok penjualan, margin, dan profit per barang</div></div>
    </div>
    <span class="badge bg-warning text-dark">{{ $product_count }} Barang</span>
</div>

<div class="report-toolbar d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
    <form class="d-flex flex-wrap gap-2 align-items-end" method="GET">
        <div><label class="d-block">Dari Tanggal</label><input type="date" name="from" class="form-control form-control-sm" value="{{ $from }}"></div>
        <div><label class="d-block">Sampai Tanggal</label><input type="date" name="to" class="form-control form-control-sm" value="{{ $to }}"></div>
        <div>
            <label class="d-block">Kategori</label>
            <select name="category_id" class="form-select form-select-sm" style="min-width:180px">
                <option value="">Semua Kategori</option>
                @foreach($categories as $category)
                    <option value="{{ $category->id }}" @selected($categoryId == $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn btn-sm btn-primary px-3"><i class="bi bi-funnel me-1"></i>Terapkan</button>
        <a href="{{ route('report.gross-profit') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
    </form>
    <div class="btn-group btn-group-sm">
        <a href="{{ route('report.gross-profit.pdf', request()->all()) }}" class="btn btn-outline-danger" target="_blank"><i class="bi bi-file-pdf me-1"></i>PDF</a>
        <a href="{{ route('report.gross-profit.excel', request()->all()) }}" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><div class="card report-stat h-100" style="--stat-color:#0d6efd"><div class="card-body d-flex align-items-center gap-3"><div class="stat-symbol"><i class="bi bi-cash-stack"></i></div><div><div class="text-muted small">Omzet Penjualan</div><div class="fw-bold">Rp {{ number_format($revenue, 0, ',', '.') }}</div></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card report-stat h-100" style="--stat-color:#dc3545"><div class="card-body d-flex align-items-center gap-3"><div class="stat-symbol"><i class="bi bi-box-arrow-in-down"></i></div><div><div class="text-muted small">Total HPP</div><div class="fw-bold">Rp {{ number_format($cost, 0, ',', '.') }}</div></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card report-stat h-100" style="--stat-color:#198754"><div class="card-body d-flex align-items-center gap-3"><div class="stat-symbol"><i class="bi bi-graph-up-arrow"></i></div><div><div class="text-muted small">Laba Kotor</div><div class="fw-bold {{ $gross_profit >= 0 ? 'text-success' : 'text-danger' }}">Rp {{ number_format($gross_profit, 0, ',', '.') }}</div></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card report-stat h-100" style="--stat-color:#c8960c"><div class="card-body d-flex align-items-center gap-3"><div class="stat-symbol"><i class="bi bi-percent"></i></div><div><div class="text-muted small">Margin Kotor</div><div class="fw-bold">{{ number_format($margin, 2, ',', '.') }}%</div><small class="text-muted">{{ $transaction_count }} transaksi</small></div></div></div></div>
</div>

<div class="alert alert-info py-2 small">
    <i class="bi bi-info-circle me-1"></i>
    HPP menggunakan harga beli terakhir yang disimpan saat transaksi terjadi. Laba kotor = omzet − HPP dan belum dikurangi biaya operasional.
</div>

<div class="card table-card">
    <div class="card-header bg-white border-0 pt-3"><h6 class="fw-bold mb-1">Profitabilitas per Barang</h6><small class="text-muted">Diurutkan berdasarkan laba kotor terbesar</small></div>
    <div class="table-responsive">
        <table class="table table-hover report-table mb-0">
            <thead><tr><th>Barang</th><th>Kategori</th><th class="text-end">Qty Grosir</th><th class="text-end">Qty Ecer</th><th class="text-end">Omzet</th><th class="text-end">HPP</th><th class="text-end">Laba Kotor</th><th class="text-end">Margin</th></tr></thead>
            <tbody>
                @forelse($products as $product)
                <tr>
                    <td><div class="fw-semibold">{{ $product->product_name }}</div><small class="text-muted">{{ $product->transaction_count }} transaksi</small></td>
                    <td><span class="badge bg-light text-dark border">{{ $product->category_name }}</span></td>
                    <td class="text-end">{{ number_format($product->wholesale_qty, 2, ',', '.') }}</td>
                    <td class="text-end">{{ number_format($product->retail_qty, 2, ',', '.') }}</td>
                    <td class="text-end">Rp {{ number_format($product->revenue, 0, ',', '.') }}</td>
                    <td class="text-end text-danger">Rp {{ number_format($product->cost, 0, ',', '.') }}</td>
                    <td class="text-end fw-bold {{ $product->gross_profit >= 0 ? 'text-success' : 'text-danger' }}">Rp {{ number_format($product->gross_profit, 0, ',', '.') }}</td>
                    <td class="text-end"><span class="badge bg-{{ $product->margin >= 20 ? 'success' : ($product->margin >= 0 ? 'warning' : 'danger') }}">{{ number_format($product->margin, 2, ',', '.') }}%</span></td>
                </tr>
                @empty
                <tr><td colspan="8" class="report-empty"><i class="bi bi-inbox fs-2 d-block mb-2"></i>Tidak ada penjualan pada periode ini</td></tr>
                @endforelse
            </tbody>
            @if($products->isNotEmpty())
            <tfoot class="table-light fw-bold"><tr><td colspan="4">TOTAL</td><td class="text-end">Rp {{ number_format($revenue, 0, ',', '.') }}</td><td class="text-end text-danger">Rp {{ number_format($cost, 0, ',', '.') }}</td><td class="text-end {{ $gross_profit >= 0 ? 'text-success' : 'text-danger' }}">Rp {{ number_format($gross_profit, 0, ',', '.') }}</td><td class="text-end">{{ number_format($margin, 2, ',', '.') }}%</td></tr></tfoot>
            @endif
        </table>
    </div>
</div>
@endsection
