@extends('layouts.app')
@section('title', 'Laporan Transfer')
@section('page-title', 'Laporan Transfer Stok')

@section('content')
<div class="report-heading d-flex justify-content-between align-items-center mb-3"><div class="d-flex align-items-center gap-3"><div class="report-icon"><i class="bi bi-arrow-left-right"></i></div><div><h5 class="fw-bold mb-1">Laporan Transfer Stok</h5><div class="text-white-50 small">Lacak perpindahan barang antar lokasi penyimpanan</div></div></div><span class="badge bg-info text-dark">{{ $transfers->count() }} Transfer</span></div>
<div class="report-toolbar d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
    <form class="d-flex gap-2 align-items-end" method="GET">
        <div><label class="d-block">Dari Tanggal</label><input type="date" name="from" class="form-control form-control-sm" value="{{ $from }}"></div>
        <div><label class="d-block">Sampai Tanggal</label><input type="date" name="to" class="form-control form-control-sm" value="{{ $to }}"></div>
        <button class="btn btn-sm btn-primary px-3"><i class="bi bi-funnel me-1"></i>Terapkan</button>
    </form>
    <div class="btn-group btn-group-sm">
        <a href="{{ route('report.transfer.pdf', request()->all()) }}" class="btn btn-outline-danger" target="_blank"><i class="bi bi-file-pdf me-1"></i>PDF</a>
        <a href="{{ route('report.transfer.excel', request()->all()) }}" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    </div>
</div>

<div class="card table-card">
    <div class="card-body p-0">
        <table class="table table-hover report-table mb-0">
            <thead><tr><th>No. Transfer</th><th>Barang</th><th>Dari</th><th>Ke</th><th class="text-end">Jumlah</th><th>Tanggal</th><th>Oleh</th></tr></thead>
            <tbody>
                @forelse($transfers as $t)
                <tr>
                    <td class="font-monospace small fw-semibold">{{ $t->transfer_number }}</td>
                    <td class="fw-semibold">{{ $t->product->name }}</td>
                    <td><span class="badge bg-danger bg-opacity-10 text-danger">{{ $t->fromLocation->name }}</span></td>
                    <td><span class="badge bg-success bg-opacity-10 text-success">{{ $t->toLocation->name }}</span></td>
                    <td class="text-end fw-semibold">{{ $t->product->displayStock((float)$t->quantity) }}</td>
                    <td>{{ $t->transfer_date->format('d/m/Y') }}</td>
                    <td class="text-muted small">{{ $t->createdBy->name ?? '-' }}</td>
                </tr>
                @empty
                <tr><td colspan="7" class="report-empty"><i class="bi bi-inbox fs-2 d-block mb-2"></i>Tidak ada transfer pada periode ini</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
