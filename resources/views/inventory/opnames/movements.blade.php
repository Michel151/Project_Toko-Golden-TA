@extends('layouts.app')
@section('title', 'Riwayat Mutasi Stok')
@section('page-title', 'Stock Opname')

@section('content')
<div class="inventory-heading"><h5><i class="bi bi-clipboard-check me-2"></i>Stock Opname</h5></div>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="btn-group btn-group-sm">
        <a href="{{ route('inventory.opnames.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-clipboard-check me-1"></i>Riwayat Opname
        </a>
        <a href="{{ route('inventory.opnames.movements') }}" class="btn btn-primary active" aria-current="page">
            <i class="bi bi-clock-history me-1"></i>Mutasi Barang
        </a>
    </div>
    <div class="d-flex gap-2">
        <span class="badge bg-success bg-opacity-10 text-success px-3 py-2"><i class="bi bi-arrow-down-left me-1"></i>{{ $totalIn }} transaksi masuk</span>
        <span class="badge bg-danger bg-opacity-10 text-danger px-3 py-2"><i class="bi bi-arrow-up-right me-1"></i>{{ $totalOut }} transaksi keluar</span>
    </div>
</div>

<div class="card table-card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3"><label class="form-label small text-muted">Barang</label><select name="product_id" class="form-select form-select-sm"><option value="">Semua Barang</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(request('product_id') == $product->id)>{{ $product->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label small text-muted">Lokasi</label><select name="location_id" class="form-select form-select-sm"><option value="">Semua Lokasi</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected(request('location_id') == $location->id)>{{ $location->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label small text-muted">Arah Mutasi</label><select name="direction" class="form-select form-select-sm"><option value="">Masuk & Keluar</option><option value="in" @selected(request('direction') === 'in')>Barang Masuk</option><option value="out" @selected(request('direction') === 'out')>Barang Keluar</option></select></div>
            <div class="col-md-2"><label class="form-label small text-muted">Dari</label><input type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm"></div>
            <div class="col-md-2"><label class="form-label small text-muted">Sampai</label><input type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm"></div>
            <div class="col-md-1 d-flex gap-1"><button class="btn btn-sm btn-primary" title="Terapkan"><i class="bi bi-search"></i></button><a href="{{ route('inventory.opnames.movements') }}" class="btn btn-sm btn-outline-secondary" title="Reset"><i class="bi bi-arrow-counterclockwise"></i></a></div>
        </form>
    </div>
</div>

<div class="card table-card">
    <div class="card-body p-0"><div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Tanggal</th><th>Barang</th><th>Lokasi</th><th>Jenis</th><th>Referensi</th><th class="text-end">Masuk/Keluar</th><th class="text-end">Saldo Awal</th><th class="text-end">Saldo Akhir</th><th>Petugas</th></tr></thead>
            <tbody>
                @forelse($movements as $movement)
                @php
                    $quantity = (float) $movement->quantity;
                    $product = $movement->product;
                    $qtyDisplay = $product->displayStock(abs($quantity));
                @endphp
                <tr>
                    <td class="text-nowrap"><div>{{ $movement->movement_date->format('d/m/Y') }}</div><small class="text-muted">{{ $movement->created_at->format('H:i') }}</small></td>
                    <td class="fw-semibold">{{ $product->name }}</td>
                    <td>{{ $movement->location->name }}</td>
                    <td><span class="badge bg-{{ $quantity > 0 ? 'success' : 'danger' }} bg-opacity-10 text-{{ $quantity > 0 ? 'success' : 'danger' }}">{{ $movement->type_label }}</span>@if($movement->notes)<div class="text-muted mt-1" style="font-size:.7rem">{{ $movement->notes }}</div>@endif</td>
                    <td><span class="font-monospace small">{{ $movement->reference_number ?? '-' }}</span></td>
                    <td class="text-end fw-bold {{ $quantity > 0 ? 'text-success' : 'text-danger' }}">{{ $quantity > 0 ? '+' : '-' }}{{ $qtyDisplay }}</td>
                    <td class="text-end small text-muted">{{ $product->displayStock((float)$movement->balance_before) }}</td>
                    <td class="text-end small fw-semibold">{{ $product->displayStock((float)$movement->balance_after) }}</td>
                    <td class="small text-muted">{{ $movement->createdBy->name ?? 'Sistem' }}</td>
                </tr>
                @empty
                <tr><td colspan="9" class="text-center text-muted py-5"><i class="bi bi-inbox fs-2 d-block mb-2"></i>Belum ada riwayat mutasi untuk filter ini</td></tr>
                @endforelse
            </tbody>
        </table>
    </div></div>
    @if($movements->hasPages())<div class="card-footer bg-white">{{ $movements->links() }}</div>@endif
</div>
@endsection
