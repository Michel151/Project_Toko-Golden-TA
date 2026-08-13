@extends('layouts.app')
@section('title', 'Transfer Stok')
@section('page-title', 'Transfer Stok')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <form class="d-flex gap-2" method="GET">
        <input type="date" name="from" class="form-control form-control-sm" style="width:140px" value="{{ request('from') }}">
        <input type="date" name="to"   class="form-control form-control-sm" style="width:140px" value="{{ request('to') }}">
        <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
    </form>
    <a href="{{ route('inventory.transfers.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-circle me-1"></i> Transfer Baru
    </a>
</div>

<div class="card table-card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>No. Transfer</th>
                    <th>Barang</th>
                    <th>Dari</th>
                    <th>Ke</th>
                    <th class="text-end">Jumlah</th>
                    <th>Tanggal</th>
                    <th>Oleh</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($transfers as $transfer)
                <tr>
                    <td class="fw-semibold">{{ $transfer->transfer_number }}</td>
                    <td>{{ $transfer->product->name }}</td>
                    <td><span class="badge bg-danger bg-opacity-10 text-danger">{{ $transfer->fromLocation->name }}</span></td>
                    <td><span class="badge bg-success bg-opacity-10 text-success">{{ $transfer->toLocation->name }}</span></td>
                    <td class="text-end fw-semibold">{{ $transfer->product->displayStock((float) $transfer->quantity) }}</td>
                    <td>{{ $transfer->transfer_date->format('d/m/Y') }}</td>
                    <td class="text-muted small">{{ $transfer->createdBy->name ?? '-' }}</td>
                    <td class="text-center">
                        <a href="{{ route('inventory.transfers.show', $transfer) }}" class="btn btn-sm btn-outline-info py-0 px-2"><i class="bi bi-eye"></i></a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">Belum ada transfer stok</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($transfers->hasPages())
    <div class="card-footer bg-white">{{ $transfers->links() }}</div>
    @endif
</div>
@endsection
