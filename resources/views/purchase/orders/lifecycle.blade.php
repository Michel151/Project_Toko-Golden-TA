@if($order->status === 'draft')
<div class="alert alert-secondary">PO ini masih berupa draf dan belum dapat digunakan untuk penerimaan barang.</div>
@endif
<div class="card table-card mb-3" id="poLifecycle">
    <div class="card-body">
        @if($order->confirmed_at)
        <div class="small mb-2">Dikonfirmasi oleh <strong>{{ $order->confirmedBy?->name ?? '-' }}</strong> pada {{ $order->confirmed_at->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB.</div>
        @elseif($order->status !== 'draft' && !$order->cancelled_at)
        <div class="small text-muted mb-2">Dokumen tercatat sebelum alur konfirmasi PO diterapkan atau berasal dari pemenuhan retur.</div>
        @endif
        @if($order->canModify() && auth()->user()->canAccessRoute('purchase.orders.confirm'))
        <form method="POST" action="{{ route('purchase.orders.confirm', $order) }}" class="mb-3" data-confirm="Periksa supplier, barang, jumlah, dan harga. Setelah dikonfirmasi, rincian PO dikunci dan penerimaan barang dapat diproses." data-confirm-title="Konfirmasi Purchase Order?" data-confirm-tone="primary" data-confirm-label="Ya, Konfirmasi PO">
            @csrf
            <button class="btn btn-primary btn-sm"><i class="bi bi-check-circle me-1"></i>Konfirmasi PO</button>
        </form>
        @endif
        @if($order->cancelled_at || $order->closed_at)
        <div class="fw-semibold">{{ $order->status_label }}</div>
        <div class="small text-muted">{{ $order->cancelledBy?->name ?? $order->closedBy?->name ?? '-' }} · {{ ($order->cancelled_at ?? $order->closed_at)->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB</div>
        <div class="mt-2 text-break">Alasan: {{ $order->cancellation_reason ?? $order->closure_reason }}</div>
        @endif
        @php
            $canCancel = $order->canCancel() && auth()->user()->canAccessRoute('purchase.orders.cancel') && ($order->status === 'draft' || auth()->user()->role === 'owner');
            $canClose = $order->canCloseRemainder() && auth()->user()->role === 'owner';
        @endphp
        @if($canCancel || $canClose)
        <form method="POST" action="{{ route($canClose ? 'purchase.orders.close-remainder' : 'purchase.orders.cancel', $order) }}" data-confirm="{{ $canClose ? 'Sisa barang yang belum diterima tidak akan dilanjutkan. Penerimaan, stok, dan nilai persediaan yang sudah tercatat tetap dipertahankan.' : 'PO akan dibatalkan dan tidak dapat diproses penerimaan. Dokumen tetap tersimpan sebagai riwayat.' }}" data-confirm-title="{{ $canClose ? 'Tutup Sisa Pesanan?' : 'Batalkan PO?' }}" data-confirm-label="{{ $canClose ? 'Ya, Tutup Sisa Pesanan' : 'Ya, Batalkan PO' }}">
            @csrf
            <label for="poLifecycleReason" class="form-label small fw-semibold">Alasan {{ $canClose ? 'Penutupan Sisa Pesanan' : 'Pembatalan' }} <span class="text-danger">*</span></label>
            <textarea id="poLifecycleReason" name="reason" class="form-control form-control-sm mb-2" rows="2" maxlength="2000" required>{{ old('reason') }}</textarea>
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle me-1"></i>{{ $canClose ? 'Tutup Sisa Pesanan' : 'Batalkan PO' }}</button>
        </form>
        @elseif($order->status === 'partial')
        <div class="small text-muted">Penutupan sisa pesanan hanya tersedia bagi owner setelah retur/klaim yang masih tertunda diselesaikan.</div>
        @endif
    </div>
</div>
