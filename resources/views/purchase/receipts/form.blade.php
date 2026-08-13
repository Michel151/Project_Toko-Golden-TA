@extends('layouts.app')
@section('title', 'Catat Penerimaan Barang')
@section('page-title', 'Catat Penerimaan Barang')

@push('styles')
<style>
    .receipt-item-toggle { background:#fff; border:0; padding:12px 16px; width:100%; text-align:left; }
    .receipt-item-toggle:hover { background:#f8f9fb; }
    .receipt-item-toggle .toggle-icon { transition:transform .2s; }
    .receipt-item-toggle[aria-expanded="true"] .toggle-icon { transform:rotate(180deg); }
    .receipt-item-detail { background:#fafbfc; border-top:1px solid #edf0f4; }
</style>
@endpush

@section('content')
<div class="procurement-heading d-flex justify-content-between align-items-center mb-3"><div class="d-flex align-items-center gap-3"><div class="heading-icon"><i class="bi bi-box-arrow-in-down"></i></div><div><h5 class="fw-bold mb-1">Catat Penerimaan Barang</h5><div class="small text-white-50">Distribusikan barang yang datang ke satu atau beberapa lokasi</div></div></div>@if(isset($selectedOrder))<span class="badge bg-warning text-dark font-monospace">{{ $selectedOrder->po_number }}</span>@endif</div>
<form method="POST" action="{{ route('purchase.receipts.store') }}">
    @csrf
    <div class="row g-3">
        <div class="col-md-5">
            <div class="card table-card">
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Purchase Order <span class="text-danger">*</span></label>
                        <select name="purchase_order_id" id="poSelect" class="form-select" required>
                            <option value="">-- Pilih PO --</option>
                            @foreach($pendingOrders as $po)
                            <option value="{{ $po->id }}" {{ (isset($selectedOrder) && $selectedOrder->id == $po->id) || old('purchase_order_id') == $po->id ? 'selected' : '' }}>
                                {{ $po->po_number }} — {{ $po->supplier->name }}
                            </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tanggal Terima <span class="text-danger">*</span></label>
                        <input type="date" name="receipt_date" class="form-control" value="{{ old('receipt_date', date('Y-m-d')) }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Catatan</label>
                        <textarea name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-7">
            <div class="card table-card">
                <div class="card-header bg-white border-0 py-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div><h6 class="fw-bold mb-0">Barang yang Diterima</h6><div class="text-muted small">Buka barang yang ingin diatur penerimaannya</div></div>
                        @if(isset($selectedOrder))
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-success bg-opacity-10 text-success allocation-progress">0 selesai</span>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0" id="expandAll">Buka Semua</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0" id="collapseAll">Tutup Semua</button>
                        </div>
                        @endif
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="itemsContainer">
                        @if(isset($selectedOrder))
                            @php $visibleItemIndex = 0; @endphp
                            @foreach($selectedOrder->items as $item)
                            @if($item->remainingQuantity() > 0)
                            <div class="border-bottom receipt-item">
                                <input type="hidden" name="items[{{ $loop->index }}][purchase_order_item_id]" value="{{ $item->id }}">
                                <input type="hidden" name="items[{{ $loop->index }}][product_id]" value="{{ $item->product_id }}">
                                <input type="hidden" name="items[{{ $loop->index }}][product_unit_id]" value="{{ $item->product_unit_id }}">
                                <input type="hidden" class="item-expirable" value="{{ $item->product->is_expirable ? 1 : 0 }}">
                                @php $unitName = $item->productUnit->unit->name ?? $item->product->buyUnit->name; @endphp
                                <button type="button" class="receipt-item-toggle d-flex justify-content-between align-items-center gap-2"
                                        data-bs-toggle="collapse" data-bs-target="#receiptItem{{ $loop->index }}"
                                        aria-expanded="{{ $visibleItemIndex === 0 ? 'true' : 'false' }}">
                                    <span><span class="fw-semibold">{{ $item->product->name }}</span><span class="text-muted small ms-1">({{ $unitName }})</span></span>
                                    <span class="d-flex align-items-center gap-2"><span class="badge bg-light text-secondary">Sisa {{ number_format($item->remainingQuantity(), 2) }}</span><span class="badge item-allocation-badge bg-success">Siap</span><i class="bi bi-chevron-down toggle-icon text-muted"></i></span>
                                </button>
                                <div id="receiptItem{{ $loop->index }}" class="collapse {{ $visibleItemIndex === 0 ? 'show' : '' }} receipt-item-detail">
                                <div class="p-3">
                                <div class="row g-2 mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label small">Sisa PO</label>
                                        <input type="text" class="form-control form-control-sm" value="{{ number_format($item->remainingQuantity(), 2) }}" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small">Diterima <span class="text-danger">*</span></label>
                                        <div class="input-group input-group-sm">
                                        <input type="number" name="items[{{ $loop->index }}][quantity_received]" class="form-control received-qty"
                                               max="{{ $item->remainingQuantity() }}" min="0" step="0.01" value="{{ $item->remainingQuantity() }}">
                                        <span class="input-group-text">{{ $unitName }}</span>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small">Tanggal Kadaluarsa @if($item->product->is_expirable)<span class="text-danger">*</span>@endif</label>
                                        <input type="date" name="items[{{ $loop->index }}][expired_date]"
                                               class="form-control form-control-sm"
                                               value="{{ old("items.{$loop->index}.expired_date") }}"
                                               @required($item->product->is_expirable)
                                               @disabled(!$item->product->is_expirable)>
                                        @if(!$item->product->is_expirable)
                                        <div class="form-text">Tidak diperlukan untuk barang ini.</div>
                                        @endif
                                    </div>
                                </div>
                                <div class="allocation-section" data-item-index="{{ $loop->index }}" data-unit="{{ $unitName }}">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <label class="form-label small fw-semibold mb-0"><i class="bi bi-geo-alt me-1"></i>Alokasi Lokasi</label>
                                        <button type="button" class="btn btn-sm btn-outline-primary py-0 add-allocation"><i class="bi bi-plus-circle me-1"></i>Tambah Lokasi</button>
                                    </div>
                                    <div class="allocation-rows">
                                        <div class="row g-2 align-items-center allocation-row mb-2">
                                            <div class="col-7">
                                                <select name="items[{{ $loop->index }}][allocations][0][location_id]" class="form-select form-select-sm allocation-location" required>
                                            @foreach($locations as $loc)
                                            <option value="{{ $loc->id }}">{{ $loc->name }}</option>
                                            @endforeach
                                                </select>
                                            </div>
                                            <div class="col-4"><div class="input-group input-group-sm"><input type="number" name="items[{{ $loop->index }}][allocations][0][quantity]" class="form-control allocation-qty" min="0" step="0.01" value="{{ $item->remainingQuantity() }}" required><span class="input-group-text">{{ $unitName }}</span></div></div>
                                            <div class="col-1"><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1 remove-allocation" disabled><i class="bi bi-trash"></i></button></div>
                                        </div>
                                    </div>
                                    <div class="small allocation-summary text-success">Teralokasi: {{ number_format($item->remainingQuantity(), 2) }} dari {{ number_format($item->remainingQuantity(), 2) }} {{ $unitName }}</div>
                                </div>
                                </div>
                                </div>
                            </div>
                            @php $visibleItemIndex++; @endphp
                            @endif
                            @endforeach
                        @else
                        <div class="text-center text-muted py-4" id="noPoMessage">
                            <i class="bi bi-arrow-left-circle fs-3 d-block mb-2"></i>
                            Pilih Purchase Order terlebih dahulu
                        </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check-circle me-1"></i>Simpan Penerimaan
            </button>
            <a href="{{ route('purchase.receipts.index') }}" class="btn btn-outline-secondary">Batal</a>
        </div>
    </div>
</form>

@push('scripts')
<script>
const receiptLocations = @json($locations->map(fn($location) => ['id' => $location->id, 'name' => $location->name])->values());

document.getElementById('poSelect').addEventListener('change', function() {
    const orderId = this.value;
    if (!orderId) return;
    window.location.href = `{{ route('purchase.receipts.create') }}?order_id=${orderId}`;
});

function updateAllocationSummary(section) {
    const itemBlock = section.closest('.border-bottom');
    const received = parseFloat(itemBlock.querySelector('.received-qty').value) || 0;
    const allocated = [...section.querySelectorAll('.allocation-qty')]
        .reduce((total, input) => total + (parseFloat(input.value) || 0), 0);
    const isValid = Math.abs(received - allocated) < 0.0001;
    const summary = section.querySelector('.allocation-summary');
    summary.textContent = `Teralokasi: ${allocated.toLocaleString('id-ID')} dari ${received.toLocaleString('id-ID')} ${section.dataset.unit}`;
    summary.classList.toggle('text-success', isValid);
    summary.classList.toggle('text-danger', !isValid);
    const badge = itemBlock.querySelector('.item-allocation-badge');
    badge.textContent = isValid ? 'Siap' : 'Belum sesuai';
    badge.classList.toggle('bg-success', isValid);
    badge.classList.toggle('bg-danger', !isValid);
    updateProgress();
    return isValid;
}

function updateProgress() {
    const badges = [...document.querySelectorAll('.item-allocation-badge')];
    const completed = badges.filter(badge => badge.classList.contains('bg-success')).length;
    const progress = document.querySelector('.allocation-progress');
    if (progress) progress.textContent = `${completed} dari ${badges.length} siap`;
}

document.querySelectorAll('.allocation-section').forEach(section => {
    let nextIndex = section.querySelectorAll('.allocation-row').length;

    section.querySelector('.add-allocation').addEventListener('click', () => {
        const options = receiptLocations.map(location => `<option value="${location.id}">${location.name}</option>`).join('');
        const row = document.createElement('div');
        row.className = 'row g-2 align-items-center allocation-row mb-2';
        row.innerHTML = `
            <div class="col-7"><select name="items[${section.dataset.itemIndex}][allocations][${nextIndex}][location_id]" class="form-select form-select-sm allocation-location" required>${options}</select></div>
            <div class="col-4"><div class="input-group input-group-sm"><input type="number" name="items[${section.dataset.itemIndex}][allocations][${nextIndex}][quantity]" class="form-control allocation-qty" min="0" step="0.01" value="0" required><span class="input-group-text">${section.dataset.unit}</span></div></div>
            <div class="col-1"><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1 remove-allocation"><i class="bi bi-trash"></i></button></div>`;
        section.querySelector('.allocation-rows').appendChild(row);
        nextIndex++;
        section.querySelectorAll('.remove-allocation').forEach(button => button.disabled = false);
        updateAllocationSummary(section);
    });

    section.addEventListener('input', event => {
        if (event.target.classList.contains('allocation-qty')) updateAllocationSummary(section);
    });
    section.addEventListener('click', event => {
        const button = event.target.closest('.remove-allocation');
        if (!button || button.disabled) return;
        button.closest('.allocation-row').remove();
        const buttons = section.querySelectorAll('.remove-allocation');
        if (buttons.length === 1) buttons[0].disabled = true;
        updateAllocationSummary(section);
    });

    section.closest('.border-bottom').querySelector('.received-qty').addEventListener('input', event => {
        const allocationInputs = section.querySelectorAll('.allocation-qty');
        if (allocationInputs.length === 1) allocationInputs[0].value = event.target.value;
        updateAllocationSummary(section);
    });
    updateAllocationSummary(section);
});

document.getElementById('expandAll')?.addEventListener('click', () => {
    document.querySelectorAll('.receipt-item-detail').forEach(element => bootstrap.Collapse.getOrCreateInstance(element, {toggle:false}).show());
});
document.getElementById('collapseAll')?.addEventListener('click', () => {
    document.querySelectorAll('.receipt-item-detail').forEach(element => bootstrap.Collapse.getOrCreateInstance(element, {toggle:false}).hide());
});

document.querySelector('form').addEventListener('submit', event => {
    const invalid = [...document.querySelectorAll('.allocation-section')]
        .some(section => !updateAllocationSummary(section));
    if (invalid) {
        event.preventDefault();
        alert('Total alokasi setiap barang harus sama dengan jumlah yang diterima.');
    }
});
</script>
@endpush
@endsection
