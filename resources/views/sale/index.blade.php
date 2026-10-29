@extends('layouts.app')
@section('title', 'Transaksi Penjualan')
@section('page-title', 'Transaksi Penjualan')
@push('styles')
<style>
.sale-list-toolbar { justify-content:space-between; }
.sale-list-filters { display:flex;flex-wrap:wrap;align-items:end;gap:12px;flex-grow:1; }
@media(max-width:767px) {
    .app-filter-bar.sale-list-toolbar { display:flex;align-items:stretch; }
    .sale-list-filters { display:grid;grid-template-columns:repeat(2,minmax(0,1fr));width:100%; }
    .sale-list-filters .app-filter-actions { grid-column:1/-1; }
}
</style>
@endpush

@section('content')
<div class="inventory-heading"><h5><i class="bi bi-cart-check me-2"></i>Transaksi Penjualan</h5></div>
<div class="app-filter-bar sale-list-toolbar mb-3">
    <form class="app-unified-filter sale-list-filters" method="GET">
        @include('components.date-range',['prefix'=>'saleList', 'fromValue'=>$from, 'toValue'=>$to])
        <div class="app-filter-field"><label for="salePayment">Pembayaran</label><select id="salePayment" name="payment_method" class="form-select form-select-sm">
            <option value="">Semua Pembayaran</option>
            <option value="tunai"    {{ request('payment_method') == 'tunai'    ? 'selected' : '' }}>Tunai</option>
            <option value="transfer" {{ request('payment_method') == 'transfer' ? 'selected' : '' }}>Transfer</option>
        </select></div>
        <div class="app-filter-field"><label for="saleStatus">Status</label><select id="saleStatus" name="status" class="form-select form-select-sm">
            <option value="">Semua Status</option>
            <option value="pending" @selected(request('status') === 'pending')>Menunggu Pembayaran</option>
            <option value="paid" @selected(request('status') === 'paid')>Lunas</option>
            <option value="cancelled" @selected(request('status') === 'cancelled')>Dibatalkan</option>
            <option value="cancelled_auto" @selected(request('status') === 'cancelled_auto')>Dibatalkan Otomatis</option>
        </select></div>
        <div class="app-filter-actions"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Terapkan</button>
        <a href="{{ route('sale.sales.index') }}" class="btn btn-sm btn-outline-secondary" title="Reset filter" aria-label="Reset filter"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset</a>
        </div>
    </form>
    @if(auth()->user()->canAccessRoute('sale.sales.create')) <a href="{{ route('sale.sales.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-circle me-1"></i> Transaksi Baru
    </a> @endif
</div>

@if(request()->hasAny(['from','to','payment_method','status']))
<div class="alert alert-info py-2 mb-3">
    Total penjualan periode ini: <strong>Rp {{ number_format($totalAmount, 0, ',', '.') }}</strong>
</div>
@endif

<div class="card table-card">
    <div class="card-body p-0 table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>No. Invoice</th>
                    <th>Pelanggan</th>
                    <th>Tanggal / Waktu</th>
                    <th class="text-center">Pembayaran</th>
                    <th>Status</th>
                    <th class="text-end">Total</th>
                    <th>Kasir</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($sales as $sale)
                <tr>
                    <td class="fw-semibold">{{ $sale->sale_number }}</td>
                    <td>
                        @if(filled($sale->customer_name))
                            {{ $sale->customer_name }}
                        @else
                            <span class="text-muted">Umum</span>
                        @endif
                    </td>
                    <td>{{ $sale->sale_date->format('d/m/Y') }}<div class="small text-muted">{{ $sale->transaction_time_label }}</div></td>
                    <td class="text-center">
                        <span class="badge bg-{{ $sale->payment_method === 'tunai' ? 'success' : 'info' }}">
                            {{ ucfirst($sale->payment_method) }}
                        </span>
                    </td>
                    <td><span class="badge bg-{{ match($sale->status) { 'paid' => 'success', 'pending' => 'warning text-dark', default => 'secondary' } }}">{{ match($sale->status) { 'paid' => 'Lunas', 'pending' => 'Menunggu Pembayaran', 'cancelled_auto' => 'Dibatalkan Otomatis', default => 'Dibatalkan' } }}</span></td>
                    <td class="text-end fw-semibold">Rp {{ number_format($sale->total_amount, 0, ',', '.') }}</td>
                    <td class="text-muted small">{{ $sale->createdBy->name ?? '-' }}</td>
                    <td class="text-center">
                        <div class="d-inline-flex align-items-center gap-1 text-nowrap">
                        <a href="{{ route('sale.sales.show', $sale) }}" class="btn btn-sm btn-outline-info py-0 px-2"><i class="bi bi-eye"></i></a>
                        @if($sale->status === 'pending')
                            @if(auth()->user()->canAccessRoute('sale.sales.edit')) <a href="{{ route('sale.sales.edit', $sale) }}" class="btn btn-sm btn-outline-warning py-0 px-2" title="Ubah transaksi"><i class="bi bi-pencil"></i></a> @endif
                            @if(auth()->user()->canAccessRoute('sale.sales.cancel'))
                                <form method="POST" action="{{ route('sale.sales.cancel', $sale) }}" class="m-0" data-confirm="Batalkan transaksi {{ $sale->sale_number }} dan kembalikan stok?" data-confirm-title="Batalkan Transaksi?" data-confirm-label="Batalkan Transaksi">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-danger app-icon-action" title="Batalkan transaksi {{ $sale->sale_number }}" aria-label="Batalkan transaksi {{ $sale->sale_number }}"><i class="bi bi-x-circle" aria-hidden="true"></i></button>
                                </form>
                            @endif
                        @endif
                        @if($sale->status === 'paid')
                            <a href="{{ route('sale.sales.print', ['sale' => $sale, 'popup' => 1]) }}" class="btn btn-sm btn-outline-secondary py-0 px-2 js-print-popup" title="Cetak Nota"><i class="bi bi-printer"></i></a>
                        @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">Belum ada transaksi penjualan</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($sales->hasPages())
    <div class="card-footer bg-white">{{ $sales->links() }}</div>
    @endif
</div>
@endsection
