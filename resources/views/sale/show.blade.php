@extends('layouts.app')
@section('title', $sale->sale_number)
@section('page-title', 'Detail Transaksi')

@section('content')
<div class="mb-3 d-flex gap-2">
    <a href="{{ route('sale.sales.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
    <a href="{{ route('sale.sales.print', ['sale' => $sale, 'popup' => 1]) }}" class="btn btn-sm btn-outline-secondary js-print-popup"><i class="bi bi-printer me-1"></i>Cetak Nota</a>
</div>
<div class="row g-3">
    <div class="col-md-4">
        <div class="card table-card">
            <div class="card-body">
                <h6 class="fw-bold mb-3">{{ $sale->sale_number }}</h6>
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted">Pelanggan</td><td>{{ $sale->customer_name ?? 'Umum' }}</td></tr>
                    <tr><td class="text-muted">Tanggal</td><td>{{ $sale->sale_date->format('d/m/Y') }}</td></tr>
                    <tr><td class="text-muted">Pembayaran</td><td><span class="badge bg-{{ $sale->payment_method === 'tunai' ? 'success' : 'info' }}">{{ ucfirst($sale->payment_method) }}</span></td></tr>
                    <tr><td class="text-muted">Total</td><td class="fw-bold">Rp {{ number_format($sale->total_amount, 0, ',', '.') }}</td></tr>
                    <tr><td class="text-muted">Bayar</td><td>Rp {{ number_format($sale->amount_paid, 0, ',', '.') }}</td></tr>
                    <tr><td class="text-muted">Kembalian</td><td>Rp {{ number_format($sale->change_amount, 0, ',', '.') }}</td></tr>
                    <tr><td class="text-muted">Kasir</td><td>{{ $sale->createdBy->name ?? '-' }}</td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card table-card">
            <div class="card-header bg-white border-0 pt-3"><h6 class="fw-bold mb-0">Daftar Barang</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Barang</th><th class="text-center">Jenis</th><th class="text-end">Qty</th><th class="text-end">Harga</th><th class="text-end">Subtotal</th><th>Sumber Stok</th></tr></thead>
                    <tbody>
                        @foreach($sale->items as $item)
                        <tr>
                            <td class="fw-semibold">{{ $item->product->name }}</td>
                            <td class="text-center"><span class="badge bg-primary">{{ $item->productUnit->unit->name ?? ucfirst($item->sale_type) }}</span></td>
                            <td class="text-end">{{ number_format($item->quantity, 2) }} {{ $item->productUnit->unit->name ?? ($item->sale_type === 'ecer' ? $item->product->retailUnit->name ?? '' : $item->product->buyUnit->name) }}</td>
                            <td class="text-end">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
                            <td class="text-end fw-semibold">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                            <td>
                                @foreach($item->sources as $src)
                                <div class="text-muted small">{{ $src->location->name }}: {{ number_format($src->quantity, 2) }}</div>
                                @endforeach
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="table-light">
                        <tr><td colspan="4" class="text-end fw-bold">TOTAL</td><td class="text-end fw-bold">Rp {{ number_format($sale->total_amount, 0, ',', '.') }}</td><td></td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
