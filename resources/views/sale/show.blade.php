@extends('layouts.app')
@section('title', $sale->sale_number)
@section('page-title', 'Detail Transaksi')

@section('content')
<div class="inventory-heading"><h5><i class="bi bi-receipt me-2"></i>{{ $sale->sale_number }}</h5></div>
<div class="mb-3 d-flex gap-2">
    <a href="{{ route('sale.sales.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
    @if($sale->status === 'paid')
        <a href="{{ route('sale.sales.print', ['sale' => $sale, 'popup' => 1]) }}" class="btn btn-sm btn-outline-secondary js-print-popup"><i class="bi bi-printer me-1"></i>Cetak Nota</a>
    @elseif($sale->status === 'pending')
        @if(auth()->user()->canAccessRoute('sale.sales.edit')) <a href="{{ route('sale.sales.edit', $sale) }}" class="btn btn-sm btn-outline-warning"><i class="bi bi-pencil me-1"></i>Ubah Transaksi</a> @endif
    @endif
</div>
<div class="row g-3">
    <div class="col-md-4">
        <div class="card table-card">
            <div class="card-body">
                <h6 class="fw-bold mb-3">{{ $sale->sale_number }}</h6>
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted">Pelanggan</td><td>{{ $sale->customer_name ?? 'Umum' }}</td></tr>
                    <tr><td class="text-muted">Status</td><td><span class="badge bg-{{ match($sale->status) { 'paid' => 'success', 'pending' => 'warning text-dark', default => 'secondary' } }}">{{ match($sale->status) { 'paid' => 'Lunas', 'pending' => 'Menunggu Pembayaran', 'cancelled_auto' => 'Dibatalkan Otomatis', default => 'Dibatalkan' } }}</span></td></tr>
                    @if($sale->status === 'pending')
                        <tr><td class="text-muted">Batas Bayar</td><td>{{ $sale->hold_until->format('d/m/Y H:i') }} WIB</td></tr>
                    @endif
                    <tr><td class="text-muted">Tanggal / Waktu</td><td>{{ $sale->sale_date->format('d/m/Y') }} · {{ $sale->transaction_time_label }}</td></tr>
                    <tr><td class="text-muted">Pembayaran</td><td><span class="badge bg-{{ $sale->payment_method === 'tunai' ? 'success' : 'info' }}">{{ ucfirst($sale->payment_method) }}</span></td></tr>
                    <tr><td class="text-muted">Total</td><td class="fw-bold">Rp {{ number_format($sale->total_amount, 0, ',', '.') }}</td></tr>
                    <tr><td class="text-muted">Bayar</td><td>Rp {{ number_format($sale->amount_paid, 0, ',', '.') }}</td></tr>
                    <tr><td class="text-muted">Kembalian</td><td>Rp {{ number_format($sale->change_amount, 0, ',', '.') }}</td></tr>
                    <tr><td class="text-muted">Kasir</td><td>{{ $sale->createdBy->name ?? '-' }}</td></tr>
                </table>
                @if($sale->status === 'pending')
                    @if(auth()->user()->canAccessRoute('sale.sales.complete')) <form method="POST" action="{{ route('sale.sales.complete', $sale) }}" class="mt-3" id="completePaymentForm" data-confirm="Pastikan pembayaran sudah diterima. Selesaikan transaksi ini?" data-confirm-title="Selesaikan Pembayaran?" data-confirm-tone="success" data-confirm-label="Selesaikan Pembayaran">
                        @csrf
                        <label class="form-label small fw-semibold">Metode Pembayaran</label>
                        <select name="payment_method" class="form-select form-select-sm mb-2" required>
                            <option value="tunai">Tunai</option>
                            <option value="transfer">Transfer</option>
                        </select>
                        <label class="form-label small fw-semibold">Jumlah Bayar</label>
                        <div class="input-group input-group-sm mb-2"><span class="input-group-text">Rp</span><input type="text" name="amount_paid" id="completeAmountPaid" class="form-control js-currency-input" value="{{ old('amount_paid') }}" placeholder="Masukkan jumlah yang diterima" inputmode="numeric" autocomplete="off" required></div>
                        @error('amount_paid')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="payment_confirmed" value="1" id="paymentConfirmed" required>
                            <label class="form-check-label small" for="paymentConfirmed">Pembayaran sudah diterima dari pelanggan</label>
                        </div>
                        @error('payment_confirmed')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
                        <div class="text-danger small mb-2 d-none" id="paymentValidation">Jumlah bayar harus mencukupi total transaksi.</div>
                        <button class="btn btn-success btn-sm w-100" id="completePaymentButton" disabled><i class="bi bi-check-circle me-1"></i>Selesaikan Pembayaran</button>
                    </form> @endif
                    @if(auth()->user()->canAccessRoute('sale.sales.cancel')) <form method="POST" action="{{ route('sale.sales.cancel', $sale) }}" class="mt-2" data-confirm="Batalkan transaksi dan kembalikan stok?" data-confirm-title="Batalkan Transaksi?" data-confirm-label="Batalkan Transaksi">
                        @csrf
                        <button class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-x-circle me-1"></i>Batalkan Transaksi</button>
                    </form> @endif
                @endif
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="card table-card">
            <div class="card-header bg-white border-0 pt-3"><h6 class="fw-bold mb-0">Daftar Barang</h6></div>
            <div class="card-body p-0 table-responsive">
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
                                <div class="text-muted small">
                                    {{ $src->location->name }}: {{ number_format($src->quantity, 2) }}
                                    @if($src->stockBatch)
                                        <span class="badge bg-light text-secondary">Kedaluwarsa {{ $src->stockBatch->expired_date?->format('d/m/Y') ?? '-' }}</span>
                                    @endif
                                </div>
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

@if($sale->status === 'pending')
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('completePaymentForm');
    const amount = document.getElementById('completeAmountPaid');
    const confirmed = document.getElementById('paymentConfirmed');
    const button = document.getElementById('completePaymentButton');
    const validation = document.getElementById('paymentValidation');
    if (!form || !amount || !confirmed || !button || !validation) return;
    const total = {{ (float) $sale->total_amount }};

    function validatePayment() {
        const paid = Number(String(amount.value || '').replace(/[^\d]/g, ''));
        const enough = paid >= total;
        validation.classList.toggle('d-none', amount.value === '' || enough);
        button.disabled = !enough || !confirmed.checked;
    }

    amount.addEventListener('input', validatePayment);
    confirmed.addEventListener('change', validatePayment);
    form.addEventListener('submit', function (event) {
        validatePayment();
        if (button.disabled) {
            event.preventDefault();
            amount.value = new Intl.NumberFormat('id-ID').format(Number(String(amount.value || '').replace(/[^\d]/g, '')) || 0);
        }
    });
});
</script>
@endpush
@endif
@endsection
