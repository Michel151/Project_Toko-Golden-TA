@extends('layouts.app')
@section('title', 'Detail Transfer')
@section('page-title', 'Detail Transfer Stok')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold mb-0">{{ $transfer->transfer_number }}</h5>
    <x-back-link :fallback="route('inventory.transfers.index')" />
</div>
<div class="app-document-meta border-bottom pb-3 mb-3">
    <span>Lokasi asal: <strong>{{ $transfer->fromLocation->name }}</strong></span><span>Lokasi tujuan: <strong>{{ $transfer->toLocation->name }}</strong></span>
    <span>Tanggal: <strong>{{ $transfer->transfer_date->format('d/m/Y') }}</strong></span><span>Dibuat oleh: <strong>{{ $transfer->createdBy->name ?? '-' }}</strong></span><span>Total: <strong>{{ $transfer->items_count }} barang</strong></span>
</div>
@if($transfer->notes)<div class="bg-white border-bottom p-3 mb-3"><span class="small text-muted">Catatan</span><div style="white-space:pre-wrap">{{ $transfer->notes }}</div></div>@endif
<form method="GET" action="{{ route('inventory.transfers.show',$transfer) }}" class="app-unified-filter d-flex flex-wrap gap-2 align-items-end mb-3" role="search">
    <div class="app-filter-field flex-grow-1" style="max-width:320px"><label for="transferDetailSearch">Nama Barang</label><input id="transferDetailSearch" name="q" value="{{ $search }}" type="search" class="form-control form-control-sm" placeholder="Cari nama barang..."></div>
    <div class="app-filter-field"><label for="transferDetailSize">Per Halaman</label><select id="transferDetailSize" name="per_page" class="form-select form-select-sm">@foreach([25,50,100] as $size)<option value="{{ $size }}" @selected((int)request('per_page',25)===$size)>{{ $size }}</option>@endforeach</select></div>
    <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Terapkan</button>
    @if($search!=='')<a href="{{ route('inventory.transfers.show',$transfer) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset</a>@endif
</form>
<div class="card table-card">
    <div class="table-responsive app-table-scroll" tabindex="0" role="region" aria-label="Daftar barang transfer">
        <table class="table table-hover align-middle mb-0" style="min-width:600px">
            <thead><tr><th>Barang</th><th>Satuan Transfer</th><th class="text-end">Jumlah</th></tr></thead>
            <tbody>@forelse($items as $item)<tr><td class="fw-semibold">{{ $item->product->name }}</td><td>{{ $item->productUnit?->unit?->name ?? $item->product->buyUnit->name }}</td><td class="text-end">{{ $item->product->displayStock((float)$item->quantity) }}</td></tr>
            @empty<tr><td colspan="3" class="text-center text-muted py-4">{{ $search!=='' ? 'Tidak ada barang yang sesuai pencarian.' : 'Belum ada barang dalam transfer ini.' }}</td></tr>@endforelse</tbody>
        </table>
    </div>
    <div class="card-footer bg-white">{{ $items->links() }}</div>
</div>
@endsection
