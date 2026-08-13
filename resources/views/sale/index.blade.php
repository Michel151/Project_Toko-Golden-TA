@extends('layouts.app')
@section('title', 'Transaksi Penjualan')
@section('page-title', 'Transaksi Penjualan')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <form class="d-flex gap-2 flex-wrap" method="GET">
        <input type="date" name="from" class="form-control form-control-sm" style="width:140px" value="{{ request('from') }}">
        <input type="date" name="to"   class="form-control form-control-sm" style="width:140px" value="{{ request('to') }}">
        <select name="payment_method" class="form-select form-select-sm" style="width:130px">
            <option value="">Semua Pembayaran</option>
            <option value="tunai"    {{ request('payment_method') == 'tunai'    ? 'selected' : '' }}>Tunai</option>
            <option value="transfer" {{ request('payment_method') == 'transfer' ? 'selected' : '' }}>Transfer</option>
        </select>
        <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
        @if(request()->hasAny(['from','to','payment_method']))
        <a href="{{ route('sale.sales.index') }}" class="btn btn-sm btn-outline-danger"><i class="bi bi-x"></i></a>
        @endif
    </form>
    <a href="{{ route('sale.sales.create') }}" class="btn btn-success btn-sm">
        <i class="bi bi-plus-circle me-1"></i> Transaksi Baru
    </a>
</div>

@if(request()->hasAny(['from','to','payment_method']))
<div class="alert alert-info py-2 mb-3">
    Total penjualan periode ini: <strong>Rp {{ number_format($totalAmount, 0, ',', '.') }}</strong>
</div>
@endif

<div class="card table-card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>No. Invoice</th>
                    <th>Pelanggan</th>
                    <th>Tanggal</th>
                    <th class="text-center">Pembayaran</th>
                    <th class="text-end">Total</th>
                    <th>Kasir</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($sales as $sale)
                <tr>
                    <td class="fw-semibold">{{ $sale->sale_number }}</td>
                    <td>{{ $sale->customer_name ?? '<span class="text-muted">Umum</span>' }}</td>
                    <td>{{ $sale->sale_date->format('d/m/Y') }}</td>
                    <td class="text-center">
                        <span class="badge bg-{{ $sale->payment_method === 'tunai' ? 'success' : 'info' }}">
                            {{ ucfirst($sale->payment_method) }}
                        </span>
                    </td>
                    <td class="text-end fw-semibold">Rp {{ number_format($sale->total_amount, 0, ',', '.') }}</td>
                    <td class="text-muted small">{{ $sale->createdBy->name ?? '-' }}</td>
                    <td class="text-center">
                        <a href="{{ route('sale.sales.show', $sale) }}" class="btn btn-sm btn-outline-info py-0 px-2"><i class="bi bi-eye"></i></a>
                        <a href="{{ route('sale.sales.print', ['sale' => $sale, 'popup' => 1]) }}" class="btn btn-sm btn-outline-secondary py-0 px-2 js-print-popup" title="Cetak Nota"><i class="bi bi-printer"></i></a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center text-muted py-4">Belum ada transaksi penjualan</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($sales->hasPages())
    <div class="card-footer bg-white">{{ $sales->links() }}</div>
    @endif
</div>
@endsection
