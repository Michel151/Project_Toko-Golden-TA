@extends('layouts.app')
@section('title', 'Detail Transfer')
@section('page-title', 'Detail Transfer Stok')

@section('content')
<div class="mb-3">
    <a href="{{ route('inventory.transfers.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
</div>
<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card table-card">
            <div class="card-body">
                <h6 class="fw-bold mb-3">{{ $transfer->transfer_number }}</h6>
                <table class="table table-sm table-borderless">
                    <tr><td class="text-muted">Barang</td><td class="fw-semibold">{{ $transfer->product->name }}</td></tr>
                    <tr><td class="text-muted">Satuan Transfer</td><td>{{ $transfer->productUnit->unit->name ?? $transfer->product->productUnits->pluck('unit.name')->filter()->implode(' / ') ?: $transfer->product->buyUnit->name }}</td></tr>
                    <tr><td class="text-muted">Dari</td><td><span class="badge bg-danger bg-opacity-10 text-danger">{{ $transfer->fromLocation->name }}</span></td></tr>
                    <tr><td class="text-muted">Ke</td><td><span class="badge bg-success bg-opacity-10 text-success">{{ $transfer->toLocation->name }}</span></td></tr>
                    <tr><td class="text-muted">Jumlah</td><td class="fw-bold">{{ $transfer->product->displayStock((float) $transfer->quantity) }}</td></tr>
                    <tr><td class="text-muted">Tanggal</td><td>{{ $transfer->transfer_date->format('d/m/Y') }}</td></tr>
                    <tr><td class="text-muted">Dilakukan Oleh</td><td>{{ $transfer->createdBy->name ?? '-' }}</td></tr>
                    @if($transfer->notes)
                    <tr><td class="text-muted">Catatan</td><td>{{ $transfer->notes }}</td></tr>
                    @endif
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
