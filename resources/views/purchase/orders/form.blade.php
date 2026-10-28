@extends('layouts.app')
@section('title', isset($order) ? 'Ubah PO' : 'Buat Purchase Order')
@section('page-title', isset($order) ? 'Ubah Purchase Order' : 'Buat Purchase Order')
@push('styles')
<style data-po-search-styles>{!! file_get_contents(public_path('vendor/tom-select-2.4.3/tom-select.bootstrap5.min.css')) !!}</style>
<style>
#itemsTable { min-width:800px; }
#itemsTable .input-group { flex-wrap:nowrap; }
.po-search-select { width:100%;min-width:0; }
.po-search-select .ts-control { border-color:var(--app-border);border-radius:6px;min-height:38px; }
#itemsTable .po-search-select .ts-control { min-height:32px;padding:4px 28px 4px 8px; }
.po-search-select .ts-control > .item { min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%; }
.po-search-select.focus .ts-control { border-color:#198754;box-shadow:0 0 0 .2rem rgba(25,135,84,.15); }
.po-search-dropdown { z-index:1040;border:1px solid var(--app-border);border-radius:6px;box-shadow:0 6px 18px rgba(0,0,0,.12); }
.po-search-dropdown .ts-dropdown-content { max-height:260px; }
.po-search-dropdown .option { padding:8px 12px;overflow-wrap:anywhere; }
.po-search-dropdown .active { background:#e6f1eb;color:#153a2b; }
</style>
@endpush

@section('content')
<div class="procurement-heading d-flex justify-content-between align-items-center mb-3"><div class="d-flex align-items-center gap-3"><div class="heading-icon"><i class="bi bi-cart-plus"></i></div><div><h5 class="fw-bold mb-1">{{ isset($order) ? 'Ubah Purchase Order' : 'Purchase Order Baru' }}</h5><div class="small text-white-50">Pilih supplier dan susun daftar barang yang akan dipesan</div></div></div>@isset($order)<span class="badge bg-warning text-dark font-monospace">{{ $order->po_number }}</span>@endisset</div>
<form method="POST" action="{{ isset($order) ? route('purchase.orders.update', $order) : route('purchase.orders.store') }}">
    @csrf @isset($order) @method('PUT') @endisset

    <div class="row g-3">
        <div class="col-md-8">
            <div class="card table-card">
                <div class="card-header bg-white border-0 pt-3">
                    <h6 class="fw-bold mb-0"><i class="bi bi-info-circle me-2"></i>Informasi PO</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="supplierSelect" class="form-label fw-semibold">Supplier <span class="text-danger">*</span></label>
                            <select name="supplier_id" id="supplierSelect" class="form-select @error('supplier_id') is-invalid @enderror" required>
                                <option value="">-- Pilih Supplier --</option>
                                @foreach($suppliers as $s)
                                <option value="{{ $s->id }}" {{ old('supplier_id', $order->supplier_id ?? '') == $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                                @endforeach
                            </select>
                            @error('supplier_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tanggal Order <span class="text-danger">*</span></label>
                            <input type="date" name="order_date" class="form-control"
                                   value="{{ old('order_date', isset($order) ? $order->order_date->format('Y-m-d') : date('Y-m-d')) }}" required>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card table-card">
                <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0"><i class="bi bi-list-ul me-2"></i>Daftar Barang</h6>
                </div>
                @error('items')<div class="alert alert-danger mx-3 mb-0">{{ $message }}</div>@enderror
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0" id="itemsTable">
                            <thead>
                                <tr>
                                    <th style="width:35%">Barang</th>
                                    <th style="width:20%">Satuan Beli</th>
                                    <th style="width:15%">Jumlah</th>
                                    <th style="width:20%">Harga Beli (Rp)</th>
                                    <th style="width:10%"></th>
                                </tr>
                            </thead>
                            <tbody id="itemsBody">
                                @php
                                    $formItems = old('items', isset($order) ? $order->items->map(fn ($item) => $item->only(['product_id', 'product_unit_id', 'quantity_ordered', 'buy_price']))->all() : []);
                                    $formItems = is_array($formItems) ? array_values(array_filter($formItems, 'is_array')) : [];
                                    if (!$formItems) $formItems = [['product_id'=>'', 'product_unit_id'=>'', 'quantity_ordered'=>1, 'buy_price'=>0]];
                                @endphp
                                    @foreach($formItems as $i => $item)
                                    @php $rowProduct = $products->firstWhere('id', $item['product_id'] ?? null); @endphp
                                    <tr class="item-row">
                                        <td>
                                            <select name="items[{{ $i }}][product_id]" class="form-select form-select-sm product-select" required>
                                                <option value="">-- Pilih Barang --</option>
                                                @foreach($products as $p)
                                                <option value="{{ $p->id }}" @selected(($item['product_id'] ?? '') == $p->id)>{{ $p->name }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td>
                                            <select name="items[{{ $i }}][product_unit_id]" class="form-select form-select-sm product-unit-select" required>
                                                <option value="">Pilih satuan</option>
                                                @foreach($rowProduct?->productUnits->sortByDesc('conversion_to_base') ?? [] as $productUnit)
                                                <option value="{{ $productUnit->id }}" data-price="{{ $productUnit->buy_price }}" @selected(($item['product_unit_id'] ?? '') == $productUnit->id)>{{ $productUnit->unit->name }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td><input type="number" name="items[{{ $i }}][quantity_ordered]" class="form-control form-control-sm" value="{{ $item['quantity_ordered'] ?? 1 }}" min="0.01" step="0.01" required></td>
                                        <td><div class="input-group input-group-sm"><span class="input-group-text">Rp</span><input type="text" name="items[{{ $i }}][buy_price]" class="form-control js-decimal-currency" value="{{ $item['buy_price'] ?? 0 }}" inputmode="decimal" required></div></td>
                                        <td><button type="button" class="btn btn-sm btn-outline-danger py-0 remove-item"><i class="bi bi-trash"></i></button></td>
                                    </tr>
                                    @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white border-top p-3">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addItemBottom" {{ old('supplier_id', $order->supplier_id ?? '') ? '' : 'disabled' }}>
                        <i class="bi bi-plus-circle me-1"></i> Tambah Barang
                    </button>
                </div>
            </div>
        </div>

        <div class="col-12 d-flex flex-wrap gap-2">
            <button type="submit" name="intent" value="draft" class="btn btn-outline-primary">
                <i class="bi bi-save me-1"></i>Simpan Draf
            </button>
            @if(auth()->user()->canAccessRoute('purchase.orders.confirm'))
            <button type="submit" name="intent" value="confirm" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Konfirmasi PO</button>
            @endif
            <a href="{{ route('purchase.orders.index') }}" class="btn btn-outline-secondary">Batal</a>
        </div>
    </div>
</form>

@push('scripts')
<script data-po-search-library>{!! file_get_contents(public_path('vendor/tom-select-2.4.3/tom-select.complete.min.js')) !!}</script>
<script data-po-search-controls>{!! file_get_contents(public_path('js/purchase-search.js')) !!}</script>
<script>
const productsBySupplier = {!! json_encode($supplierProductsJson) !!};
const supplierSelect = document.getElementById('supplierSelect');
const addItemBottomButton = document.getElementById('addItemBottom');
let rowIndex = {{ count($formItems) }};
let activeSupplierId = supplierSelect.value;
let changingSupplier = false;

function currentProducts() {
    return productsBySupplier[supplierSelect.value] || [];
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    }[char]));
}

function buildProductOptions(selectedId = '') {
    return currentProducts().map(p =>
        `<option value="${p.id}" ${p.id == selectedId ? 'selected' : ''}>${escapeHtml(p.name)}</option>`
    ).join('');
}

function buildUnitOptions(product, selectedId = '') {
    if (!product || !product.units || !product.units.length) {
        return '<option value="">Pilih satuan</option>';
    }

    return product.units.map(unit => {
        const selected = String(unit.id) === String(selectedId) || (!selectedId && unit.is_default_purchase) ? 'selected' : '';
        return `<option value="${unit.id}" data-price="${unit.buy_price}" ${selected}>${escapeHtml(unit.name)}</option>`;
    }).join('');
}

function selectedProduct(row) {
    const productId = row.querySelector('select.product-select').value;
    return currentProducts().find(product => String(product.id) === String(productId));
}

function updateUnitOptions(row, selectedUnitId = '') {
    const product = selectedProduct(row);
    const unitSelect = row.querySelector('.product-unit-select');
    unitSelect.innerHTML = buildUnitOptions(product, selectedUnitId);
    const selectedUnit = unitSelect.options[unitSelect.selectedIndex];
    row.querySelector('[name$="[buy_price]"]').value = formatDecimalCurrencyValue(selectedUnit?.dataset.price ?? product?.price ?? 0);
    showPriceSource(row, product?.price_source === 'master' ? 'Acuan Master Barang' : 'Harga supplier');
}

function showPriceSource(row, text) {
    let label = row.querySelector('[data-price-origin]');
    if (!label) {
        label = document.createElement('div');
        label.dataset.priceOrigin = '';
        label.className = 'small text-muted mt-1';
        row.querySelector('[name$="[buy_price]"]').closest('td').appendChild(label);
    }
    label.textContent = text;
}

function createItemRow(index) {
    const row = document.createElement('tr');
    row.className = 'item-row';
    row.innerHTML = `
        <td>
            <select name="items[${index}][product_id]" class="form-select form-select-sm product-select" required>
                <option value="">-- Pilih Barang --</option>
                ${buildProductOptions()}
            </select>
        </td>
        <td><select name="items[${index}][product_unit_id]" class="form-select form-select-sm product-unit-select" required><option value="">Pilih satuan</option></select></td>
        <td><input type="number" name="items[${index}][quantity_ordered]" class="form-control form-control-sm" min="0.01" step="0.01" value="1" required></td>
        <td><div class="input-group input-group-sm"><span class="input-group-text">Rp</span><input type="text" name="items[${index}][buy_price]" class="form-control js-decimal-currency" inputmode="decimal" value="0" required></div></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger py-0 remove-item"><i class="bi bi-trash"></i></button></td>
    `;
    bindEvents(row);
    initializeDecimalCurrencyInputs(row);
    return row;
}

function addItemRow() {
    if (! supplierSelect.value) {
        return;
    }
    const row = createItemRow(rowIndex);
    document.getElementById('itemsBody').appendChild(row);
    initializeProductSearch();
    rowIndex++;
    requestAnimationFrame(() => addItemBottomButton.scrollIntoView({ block: 'end', behavior: 'instant' }));
}
addItemBottomButton.addEventListener('click', addItemRow);

function bindEvents(container = document) {
    container.querySelectorAll('select.product-select').forEach(sel => {
        let previousProductId = sel.value;
        let confirmingDuplicate = false;
        sel.addEventListener('change', async function() {
            const row  = this.closest('tr');
            const nextProductId = this.value;
            window.purchaseSearch.setValue(this, previousProductId);
            if (confirmingDuplicate || nextProductId === previousProductId) return;
            const duplicate = nextProductId && [...document.querySelectorAll('select.product-select')]
                .some(select => select !== this && select.value === nextProductId);
            if (duplicate) {
                confirmingDuplicate = true;
                try {
                    const product = currentProducts().find(item => String(item.id) === nextProductId);
                    const accepted = await window.appDialog.confirm({
                        title: 'Barang Sudah Ada',
                        text: `${product?.name || 'Barang ini'} sudah ada dalam daftar PO. Yakin ingin menambahkannya pada baris terpisah?`,
                        confirmText: 'Ya, Tambahkan',
                        tone: 'danger',
                    });
                    if (!accepted || !row.isConnected) return;
                } finally { confirmingDuplicate = false; }
            }
            window.purchaseSearch.setValue(this, nextProductId);
            previousProductId = nextProductId;
            updateUnitOptions(row);
        });
    });
    container.querySelectorAll('.product-unit-select').forEach(sel => {
        sel.addEventListener('change', function() {
            const row  = this.closest('tr');
            row.querySelector('[name$="[buy_price]"]').value = formatDecimalCurrencyValue(this.options[this.selectedIndex]?.dataset.price ?? 0);
            showPriceSource(row, selectedProduct(row)?.price_source === 'master' ? 'Acuan Master Barang' : 'Harga supplier');
        });
    });
    container.querySelectorAll('.remove-item').forEach(btn => {
        btn.addEventListener('click', function() {
            if (document.querySelectorAll('.item-row').length > 1) {
                window.purchaseSearch.destroy(this.closest('tr'));
                this.closest('tr').remove();
            }
        });
    });
    container.querySelectorAll('[name$="[buy_price]"]').forEach(input => {
        input.addEventListener('input', () => showPriceSource(input.closest('tr'), 'Harga PO (manual)'));
    });
}

supplierSelect.addEventListener('change', async () => {
    const next = supplierSelect.value;
    window.purchaseSearch.setValue(supplierSelect, activeSupplierId);
    if (changingSupplier || next === activeSupplierId) return;
    changingSupplier = true;
    try {
        const populated = [...document.querySelectorAll('select.product-select')].some(select => select.value);
        if (populated) {
            const accepted = await window.appDialog.confirm({ title: 'Ganti Supplier?', text: 'Daftar barang dan harga PO saat ini akan dikosongkan.', confirmText: 'Ganti Supplier', tone: 'danger' });
            if (!accepted) return;
        }
        window.purchaseSearch.setValue(supplierSelect, next);
        activeSupplierId = next;
        addItemBottomButton.disabled = ! next;
        rowIndex = 0;
        const tbody = document.getElementById('itemsBody');
        window.purchaseSearch.destroy(tbody);
        tbody.innerHTML = '';
        tbody.appendChild(createItemRow(rowIndex));
        initializeProductSearch();
        rowIndex++;
    } finally { changingSupplier = false; }
});

bindEvents();
function initializeProductSearch() {
    document.querySelectorAll('select.product-select').forEach(select => {
        select.disabled = !supplierSelect.value;
        window.purchaseSearch.attach(select, currentProducts());
    });
}
document.querySelectorAll('.item-row').forEach(row => {
    if (row.querySelector('select.product-select')?.value) {
        const unitSelect = row.querySelector('.product-unit-select');
        if (!unitSelect.value) updateUnitOptions(row);
        else {
            unitSelect.innerHTML = buildUnitOptions(selectedProduct(row), unitSelect.value);
            showPriceSource(row, {{ Js::from(old('items') ? 'Harga PO (belum disimpan)' : 'Harga PO tersimpan') }});
        }
    }
});
window.purchaseSearch.attach(supplierSelect);
initializeProductSearch();
const orderForm = supplierSelect.closest('form');
let confirmationAccepted = false;
let confirmingOrder = false;
orderForm.addEventListener('submit', async event => {
    if (confirmationAccepted) return;
    if (confirmingOrder) { event.preventDefault(); return; }
    if (event.submitter?.value !== 'confirm') return;
    event.preventDefault();
    confirmingOrder = true;
    try {
        const accepted = await window.appDialog.confirm({
            title: 'Konfirmasi Purchase Order?',
            text: 'Periksa kembali barang, jumlah, dan harga. Setelah dikonfirmasi, rincian PO tidak dapat diubah dan barang dapat diproses penerimaan.',
            details: [['Supplier', supplierSelect.selectedOptions[0]?.textContent || '-'], ['Jumlah Baris Barang', String(document.querySelectorAll('.item-row').length)]],
            confirmText: 'Ya, Konfirmasi PO',
        });
        if (!accepted || !orderForm.reportValidity()) return;
        confirmationAccepted = true;
        orderForm.requestSubmit(event.submitter);
    } finally { confirmingOrder = false; confirmationAccepted = false; }
});
</script>
@endpush
@endsection
