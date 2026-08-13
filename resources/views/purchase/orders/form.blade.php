@extends('layouts.app')
@section('title', isset($order) ? 'Edit PO' : 'Buat Purchase Order')
@section('page-title', isset($order) ? 'Edit Purchase Order' : 'Buat Purchase Order')

@section('content')
<div class="procurement-heading d-flex justify-content-between align-items-center mb-3"><div class="d-flex align-items-center gap-3"><div class="heading-icon"><i class="bi bi-cart-plus"></i></div><div><h5 class="fw-bold mb-1">{{ isset($order) ? 'Edit Purchase Order' : 'Purchase Order Baru' }}</h5><div class="small text-white-50">Pilih supplier dan susun daftar barang yang akan dipesan</div></div></div>@isset($order)<span class="badge bg-warning text-dark font-monospace">{{ $order->po_number }}</span>@endisset</div>
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
                            <label class="form-label fw-semibold">Supplier <span class="text-danger">*</span></label>
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
                    <button type="button" class="btn btn-sm btn-outline-primary" id="addItem" {{ old('supplier_id', $order->supplier_id ?? '') ? '' : 'disabled' }}>
                        <i class="bi bi-plus-circle me-1"></i> Tambah Barang
                    </button>
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
                                @if(isset($order) && $order->items->count())
                                    @foreach($order->items as $i => $item)
                                    <tr class="item-row">
                                        <td>
                                            <select name="items[{{ $i }}][product_id]" class="form-select form-select-sm product-select" required>
                                                <option value="">-- Pilih Barang --</option>
                                                @foreach($products as $p)
                                                <option value="{{ $p->id }}" {{ $item->product_id == $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td>
                                            <select name="items[{{ $i }}][product_unit_id]" class="form-select form-select-sm product-unit-select" required>
                                                @foreach($item->product->productUnits->sortByDesc('conversion_to_base') as $productUnit)
                                                <option value="{{ $productUnit->id }}" data-price="{{ $productUnit->buy_price }}" @selected($item->product_unit_id == $productUnit->id)>{{ $productUnit->unit->name }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td><input type="number" name="items[{{ $i }}][quantity_ordered]" class="form-control form-control-sm" value="{{ $item->quantity_ordered }}" min="0.01" step="0.01" required></td>
                                        <td><div class="input-group input-group-sm"><span class="input-group-text">Rp</span><input type="text" name="items[{{ $i }}][buy_price]" class="form-control js-currency-input" value="{{ number_format((float) $item->buy_price, 0, ',', '.') }}" inputmode="numeric" required></div></td>
                                        <td><button type="button" class="btn btn-sm btn-outline-danger py-0 remove-item"><i class="bi bi-trash"></i></button></td>
                                    </tr>
                                    @endforeach
                                @else
                                <tr class="item-row">
                                    <td>
                                        <select name="items[0][product_id]" class="form-select form-select-sm product-select" required>
                                            <option value="">-- Pilih Barang --</option>
                                            @foreach($products as $p)
                                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td><select name="items[0][product_unit_id]" class="form-select form-select-sm product-unit-select" required><option value="">Pilih satuan</option></select></td>
                                    <td><input type="number" name="items[0][quantity_ordered]" class="form-control form-control-sm" min="0.01" step="0.01" value="1" required></td>
                                    <td><div class="input-group input-group-sm"><span class="input-group-text">Rp</span><input type="text" name="items[0][buy_price]" class="form-control js-currency-input" inputmode="numeric" value="0" required></div></td>
                                    <td><button type="button" class="btn btn-sm btn-outline-danger py-0 remove-item"><i class="bi bi-trash"></i></button></td>
                                </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1"></i>{{ isset($order) ? 'Perbarui PO' : 'Simpan PO' }}
            </button>
            <a href="{{ route('purchase.orders.index') }}" class="btn btn-outline-secondary">Batal</a>
        </div>
    </div>
</form>

@push('scripts')
<script>
const productsBySupplier = {!! json_encode($supplierProductsJson) !!};
const supplierSelect = document.getElementById('supplierSelect');
const addItemButton = document.getElementById('addItem');
let rowIndex = {{ isset($order) ? $order->items->count() : 1 }};

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
    const productId = row.querySelector('.product-select').value;
    return currentProducts().find(product => String(product.id) === String(productId));
}

function updateUnitOptions(row, selectedUnitId = '') {
    const product = selectedProduct(row);
    const unitSelect = row.querySelector('.product-unit-select');
    unitSelect.innerHTML = buildUnitOptions(product, selectedUnitId);
    const selectedUnit = unitSelect.options[unitSelect.selectedIndex];
    row.querySelector('[name$="[buy_price]"]').value = formatCurrencyValue(selectedUnit?.dataset.price || product?.price || 0);
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
        <td><div class="input-group input-group-sm"><span class="input-group-text">Rp</span><input type="text" name="items[${index}][buy_price]" class="form-control js-currency-input" inputmode="numeric" value="0" required></div></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger py-0 remove-item"><i class="bi bi-trash"></i></button></td>
    `;
    bindEvents(row);
    initializeCurrencyInputs(row);
    return row;
}

addItemButton.addEventListener('click', () => {
    if (! supplierSelect.value) {
        return;
    }
    document.getElementById('itemsBody').appendChild(createItemRow(rowIndex));
    rowIndex++;
});

function bindEvents(container = document) {
    container.querySelectorAll('.product-select').forEach(sel => {
        sel.addEventListener('change', function() {
            const row  = this.closest('tr');
            updateUnitOptions(row);
        });
    });
    container.querySelectorAll('.product-unit-select').forEach(sel => {
        sel.addEventListener('change', function() {
            const row  = this.closest('tr');
            row.querySelector('[name$="[buy_price]"]').value = formatCurrencyValue(this.options[this.selectedIndex]?.dataset.price || 0);
        });
    });
    container.querySelectorAll('.remove-item').forEach(btn => {
        btn.addEventListener('click', function() {
            if (document.querySelectorAll('.item-row').length > 1) {
                this.closest('tr').remove();
            }
        });
    });
}

supplierSelect.addEventListener('change', () => {
    addItemButton.disabled = ! supplierSelect.value;
    rowIndex = 0;
    const tbody = document.getElementById('itemsBody');
    tbody.innerHTML = '';
    tbody.appendChild(createItemRow(rowIndex));
    rowIndex++;
});

bindEvents();
document.querySelectorAll('.item-row').forEach(row => {
    if (row.querySelector('.product-select')?.value && !row.querySelector('.product-unit-select')?.value) {
        updateUnitOptions(row);
    }
});
</script>
@endpush
@endsection
