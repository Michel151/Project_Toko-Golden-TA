@extends('layouts.app')
@section('title', 'Transaksi Baru')
@section('page-title', 'Transaksi Penjualan Baru')

@push('styles')
<style>
.pos-heading { background:linear-gradient(120deg,#172039,#27324a); color:#fff; border-radius:14px; padding:1rem 1.25rem; }
.pos-items-card { border:0; border-radius:14px; box-shadow:0 3px 16px rgba(25,35,55,.07); }
.pos-items-card .card-header { position:sticky; top:0; z-index:2; }
.pos-items-scroll { max-height:clamp(420px,calc(100vh - 370px),650px); overflow-y:auto; overscroll-behavior:contain; scrollbar-gutter:stable; }
.pos-checkout { position:sticky; top:78px; border:0; border-radius:14px; box-shadow:0 5px 22px rgba(25,35,55,.10); overflow:hidden; }
.pos-total { background:linear-gradient(125deg,#172039,#27324a); color:#fff; padding:1.25rem; }
.pos-total #grandTotal { background:transparent; border:0; color:#ffc107; font-size:1.7rem; font-weight:800; padding:0; box-shadow:none; }
.pos-change { background:#ecf9f1; border:1px solid #ccebd8; border-radius:10px; padding:.75rem; }
.pos-change #changeAmount { background:transparent; border:0; color:#198754; font-weight:700; padding:0; box-shadow:none; }
.item-block { border:1px solid #e4e8f0 !important; border-radius:12px !important; background:#fff; box-shadow:0 2px 8px rgba(25,35,55,.035); }
.item-block:hover { border-color:#c8d0df !important; }
.source-section { background:#f8f9fb; border-radius:9px; padding:.75rem; border:1px dashed #dce1ea; }
.source-locked { background:#eef8f1; border:1px solid #d5ebdc; border-radius:8px; padding:8px 12px; font-size:.85rem; }
.scanner-box{background:#fff8df;border:1px solid #f1d77d;border-radius:11px;padding:.75rem 1rem}.scanner-box .form-control{border-color:#e7c24d}.scanner-status{min-height:18px}
.catalog-tabs { flex-wrap:nowrap; overflow-x:auto; padding-bottom:.25rem; scrollbar-width:thin; }
.catalog-tabs .nav-link { white-space:nowrap; color:#566078; border:1px solid #e2e6ed; background:#fff; }
.catalog-tabs .nav-link.active { color:#fff; background:#172039; border-color:#172039; }
.catalog-list { max-height:55vh; overflow-y:auto; overflow-x:hidden; }
.catalog-product { height:100%; border:1px solid #e2e6ed; border-radius:11px; background:#fff; padding:.85rem; text-align:left; transition:.15s ease; }
.catalog-product:hover { border-color:#198754; box-shadow:0 4px 14px rgba(25,135,84,.12); transform:translateY(-1px); }
.catalog-product .product-name { color:#172039; font-weight:700; }
@media(max-width:1199px){.pos-checkout{position:static}.pos-items-card .card-header{position:static}.pos-items-scroll{max-height:none;overflow:visible}}
</style>
@endpush

@section('content')
<form method="POST" action="{{ route('sale.sales.store') }}" id="saleForm">
    @csrf
    <div class="pos-heading d-flex justify-content-between align-items-center mb-3">
        <div><h5 class="fw-bold mb-1"><i class="bi bi-cart3 text-warning me-2"></i>Transaksi Penjualan</h5><div class="small text-white-50">Pilih barang, tentukan sumber stok, lalu selesaikan pembayaran.</div></div>
        <span class="badge bg-warning text-dark" id="itemCounter">0 Barang</span>
    </div>
    <div class="row g-3 align-items-start">
        <div class="col-xl-8">
            <div class="scanner-box mb-3">
                <div class="row g-2 align-items-center"><div class="col-md-4"><div class="fw-bold"><i class="bi bi-upc-scan me-2"></i>Scan Barcode</div><div class="small text-muted">Klik kolom lalu pindai barcode barang</div></div><div class="col-md-8"><div class="input-group"><span class="input-group-text bg-white"><i class="bi bi-upc"></i></span><input type="text" id="barcodeScanner" class="form-control" autocomplete="off" placeholder="Scan atau ketik kode, lalu Enter"><button type="button" id="barcodeSearch" class="btn btn-warning">Tambahkan</button></div><div id="scannerStatus" class="small scanner-status mt-1"></div></div></div>
            </div>
            <div class="card pos-items-card">
                <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                    <div><h6 class="fw-bold mb-1">Keranjang Penjualan</h6><small class="text-muted">Tambahkan barang dan jumlah yang dibeli</small></div>
                    <button type="button" class="btn btn-sm btn-success px-3" id="addItem"><i class="bi bi-plus-circle me-1"></i>Tambah Barang</button>
                </div>
                <div class="card-body pt-1 pos-items-scroll" id="itemsContainer"></div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card pos-checkout">
                <div class="pos-total"><div class="small text-white-50 text-uppercase mb-1">Total Pembayaran</div><div class="d-flex align-items-center"><span class="fs-5 me-2">Rp</span><input type="text" id="grandTotal" class="form-control" value="0" readonly></div></div>
                <div class="card-body">
                    <h6 class="fw-bold mb-3"><i class="bi bi-receipt me-2 text-primary"></i>Informasi Transaksi</h6>
                    <div class="row g-2 mb-3">
                        <div class="col-6"><label class="form-label small fw-semibold">Tanggal <span class="text-danger">*</span></label><input type="date" name="sale_date" class="form-control form-control-sm" value="{{ old('sale_date', date('Y-m-d')) }}" required></div>
                        <div class="col-6"><label class="form-label small fw-semibold">Pembayaran <span class="text-danger">*</span></label><select name="payment_method" class="form-select form-select-sm" required><option value="tunai" @selected(old('payment_method') === 'tunai')>Tunai</option><option value="transfer" @selected(old('payment_method') === 'transfer')>Transfer</option></select></div>
                        <div class="col-12"><label class="form-label small fw-semibold">Nama Pelanggan</label><input type="text" name="customer_name" class="form-control form-control-sm" value="{{ old('customer_name') }}" placeholder="Pelanggan umum (opsional)"></div>
                    </div>
                    <label class="form-label small fw-semibold">Jumlah Bayar <span class="text-danger">*</span></label>
                    <div class="input-group mb-3"><span class="input-group-text">Rp</span><input type="text" name="amount_paid" id="amountPaid" class="form-control fw-semibold js-currency-input" value="{{ number_format((float) old('amount_paid', 0), 0, ',', '.') }}" inputmode="numeric" required></div>
                    <div class="pos-change d-flex justify-content-between align-items-center mb-3"><div><div class="small text-muted">Kembalian</div><div class="small text-success"><i class="bi bi-check-circle me-1"></i>Hitung otomatis</div></div><div class="d-flex align-items-center"><span class="text-success me-1">Rp</span><input type="text" id="changeAmount" class="form-control text-end" value="0" readonly style="width:120px"></div></div>
                    <button type="submit" class="btn btn-success w-100 py-2 fw-semibold"><i class="bi bi-check-circle me-1"></i>Simpan Transaksi</button>
                    <a href="{{ route('sale.sales.index') }}" class="btn btn-link text-secondary w-100 mt-1 text-decoration-none">Batal</a>
                </div>
            </div>
        </div>
    </div>
</form>

<div class="modal fade" id="productCatalogModal" tabindex="-1" aria-labelledby="productCatalogTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold" id="productCatalogTitle"><i class="bi bi-grid me-2 text-primary"></i>Pilih Barang</h5>
                    <div class="small text-muted">Cari berdasarkan nama atau barcode, lalu pilih barang.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="input-group mb-3">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="search" class="form-control" id="catalogSearch" placeholder="Cari nama barang atau barcode..." autocomplete="off">
                </div>
                <div class="nav nav-pills gap-2 catalog-tabs mb-3" id="catalogCategories"></div>
                <div class="catalog-list pe-1">
                    <div class="row g-2" id="catalogProducts"></div>
                    <div class="text-center text-muted py-5 d-none" id="catalogEmpty">
                        <i class="bi bi-search fs-2 d-block mb-2"></i>Barang tidak ditemukan.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
const products  = {!! json_encode($productsJson) !!};
const locations = {!! json_encode($locationsJson) !!};
const oldItems  = @json(old('items', []));

const tokoLocation = locations.find(l => l.name.toLowerCase() === 'toko') || locations[0];

let itemIndex = 0;
let activeCatalogRow = null;
let activeCatalogCategory = 'all';

function formatNumber(n) {
    return new Intl.NumberFormat('id-ID').format(Math.round(n));
}

function parseCurrency(value) {
    return Number(String(value ?? '').replace(/[^\d]/g, '')) || 0;
}

function updateTotal() {
    let total = 0;
    document.querySelectorAll('.item-subtotal').forEach(el => {
        total += parseFloat(el.dataset.value || 0);
    });
    document.getElementById('grandTotal').value = formatNumber(total);
    const paid = parseCurrency(document.getElementById('amountPaid').value);
    document.getElementById('changeAmount').value = formatNumber(Math.max(0, paid - total));
}

function updateItemCounter() {
    const count = [...document.querySelectorAll('.product-select')].filter(select => select.value).length;
    document.getElementById('itemCounter').textContent = `${count} Barang`;
}

document.getElementById('amountPaid').addEventListener('input', updateTotal);

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    })[char]);
}

function buildProductOptions() {
    return products.map(p => `<option value="${escapeHtml(p.id)}">${escapeHtml(p.name)}</option>`).join('');
}

function buildProductSuggestions() {
    return products.map(p => {
        const barcode = p.barcode ? `Barcode: ${p.barcode}` : 'Tanpa barcode';
        return `<option value="${escapeHtml(p.name)}">${escapeHtml(barcode)}</option>`;
    }).join('');
}

function formatStok(rawQty, product) {
    if (product && product.product_units && product.product_units.length) {
        const units = [...product.product_units].sort((a, b) => b.conversion_to_base - a.conversion_to_base);
        let remaining = rawQty;
        const parts = [];
        units.forEach(unit => {
            const conversion = Number(unit.conversion_to_base || 0);
            if (conversion <= 0) return;
            const amount = Math.floor(remaining / conversion);
            if (amount > 0) {
                parts.push(`${formatNumber(amount)} ${unit.unit_name}`);
                remaining = remaining % conversion;
            }
        });
        return parts.length ? parts.join(' ') : `0 ${units[units.length - 1].unit_name}`;
    }

    if (product && product.can_retail && product.conversion_factor > 0) {
        const wholeDus  = Math.floor(rawQty / product.conversion_factor);
        const sisaBotol = Math.round(rawQty % product.conversion_factor);
        return sisaBotol > 0
            ? `${wholeDus} ${product.buy_unit_name} ${sisaBotol} ${product.retail_unit_name}`
            : `${wholeDus} ${product.buy_unit_name}`;
    }
    return `${Math.floor(rawQty)} ${product ? product.buy_unit_name : ''}`;
}

function buildLocationOptions(selectedId, availableStocks, product) {
    let options = locations.map(loc => {
        const stockInfo = availableStocks ? availableStocks.find(s => s.location_id == loc.id) : null;
        const rawQty    = stockInfo ? stockInfo.quantity : 0;
        const display   = formatStok(rawQty, product);
        const selected  = loc.id == selectedId ? 'selected' : '';
        return `<option value="${loc.id}" data-qty="${rawQty}" ${selected}>${loc.name} (Stok: ${display})</option>`;
    }).join('');
    options += `<option value="manual">── Input Manual Per Lokasi ──</option>`;
    return options;
}

function updateAvailableStock(content, locSelect, product) {
    const selectedOpt = locSelect.options[locSelect.selectedIndex];
    const rawQty      = parseFloat(selectedOpt.dataset.qty || 0);
    const availEl     = content.querySelector('.available-qty');
    if (!availEl) return;
    availEl.textContent = formatStok(rawQty, product);
}

function syncEcerQty(div) {
    const qtyInput  = div.querySelector('.qty-input');
    const autoInput = div.querySelector('.source-auto-qty');
    if (!autoInput) return;
    function sync() { autoInput.value = qtyInput.value || 0; }
    sync();
    qtyInput.addEventListener('input', sync);
}

function selectedProductUnit(div, product) {
    const unitId = div.querySelector('.product-unit-select').value;
    return product.product_units.find(unit => unit.id == unitId) || product.product_units[0];
}

function renderSource(div, idx, product, saleType) {
    const section = div.querySelector('.source-section');
    const label   = div.querySelector('.source-label');
    const content = div.querySelector('.source-content');

    section.classList.remove('d-none');

    if (saleType === 'ecer') {
        if (!tokoLocation) {
            content.innerHTML = `<div class="alert alert-danger py-1 small">Lokasi "Toko" tidak ditemukan.</div>`;
            return;
        }

        const tokoStock = product.stocks.find(s => s.location_id == tokoLocation.id);
        const rawQty    = tokoStock ? tokoStock.quantity : 0;
        const displayQty = formatStok(rawQty, product);

        label.textContent = 'Ecer — hanya dari Toko';
        label.className   = 'source-label badge bg-success';

        content.innerHTML = `
            <input type="hidden" name="items[${idx}][sources][0][location_id]" value="${tokoLocation.id}">
            <div class="source-locked d-flex align-items-center gap-3">
                <i class="bi bi-lock-fill text-success"></i>
                <span class="fw-semibold">${tokoLocation.name}</span>
                <span class="badge bg-success bg-opacity-10 text-success">Stok: ${displayQty}</span>
                <span class="text-muted small">Jumlah otomatis dari qty yang diisi</span>
            </div>
            <input type="hidden" class="source-auto-qty" name="items[${idx}][sources][0][quantity]" value="0">
        `;

        syncEcerQty(div);

    } else {
        label.textContent = 'Grosir — pilih sumber';
        label.className   = 'source-label badge bg-primary';

        const defaultLocId = tokoLocation ? tokoLocation.id : (product.stocks[0] ? product.stocks[0].location_id : '');

        const manualRows = locations.map((loc, si) => {
            const stockInfo = product.stocks.find(s => s.location_id == loc.id);
            const rawQty    = stockInfo ? stockInfo.quantity : 0;
            const dQty      = formatStok(rawQty, product);
            return `
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="small fw-semibold" style="min-width:80px">${loc.name}</span>
                <div class="input-group input-group-sm" style="width:160px">
                    <input type="number" name="items[${idx}][sources][${si}][quantity]"
                           class="form-control manual-qty" value="0" min="0" step="0.01" placeholder="0" disabled>
                    <span class="input-group-text">${escapeHtml(selectedProductUnit(div, product)?.unit_name || product.buy_unit_name)}</span>
                </div>
                <input type="hidden" class="manual-location-id" name="items[${idx}][sources][${si}][location_id]" value="${loc.id}" disabled>
                <span class="text-muted small">Stok: <strong>${dQty}</strong></span>
            </div>`;
        }).join('');

        content.innerHTML = `
            <div class="row g-2 align-items-center">
                <div class="col-md-5">
                    <select class="form-select form-select-sm source-location-select">
                        ${buildLocationOptions(defaultLocId, product.stocks, product)}
                    </select>
                    <input type="hidden" class="source-location-input"
                           name="items[${idx}][sources][0][location_id]" value="${defaultLocId}">
                </div>
                <div class="col-md-3 source-single-qty">
                    <div class="input-group input-group-sm">
                        <input type="number" name="items[${idx}][sources][0][quantity]"
                               class="form-control source-qty-input"
                               value="${div.querySelector('.qty-input').value || 0}" min="0" step="0.01" readonly>
                        <span class="input-group-text">${escapeHtml(selectedProductUnit(div, product)?.unit_name || product.buy_unit_name)}</span>
                    </div>
                </div>
                <div class="col-md-4 text-muted small source-single-qty">
                    Stok tersedia: <strong class="available-qty">-</strong>
                </div>
            </div>
            <div class="source-manual-rows d-none mt-2">
                <div class="text-muted small mb-2 fw-semibold">Input jumlah per lokasi:</div>
                ${manualRows}
            </div>
        `;

        const locSelect = content.querySelector('.source-location-select');
        updateAvailableStock(content, locSelect, product);

        locSelect.addEventListener('change', function() {
            const singleLocation = content.querySelector('.source-location-input');
            const singleQty = content.querySelector('.source-qty-input');
            const manualInputs = content.querySelectorAll('.manual-qty, .manual-location-id');

            if (this.value === 'manual') {
                content.querySelectorAll('.source-single-qty').forEach(el => el.classList.add('d-none'));
                content.querySelector('.source-manual-rows').classList.remove('d-none');
                singleLocation.disabled = true;
                singleQty.disabled = true;
                manualInputs.forEach(input => input.disabled = false);
            } else {
                content.querySelectorAll('.source-single-qty').forEach(el => el.classList.remove('d-none'));
                content.querySelector('.source-manual-rows').classList.add('d-none');
                content.querySelectorAll('.manual-qty').forEach(i => i.value = 0);
                singleLocation.disabled = false;
                singleLocation.value = this.value;
                singleQty.disabled = false;
                manualInputs.forEach(input => input.disabled = true);
                updateAvailableStock(content, locSelect, product);
            }
        });
    }
}

function addItem(reveal = true) {
    const idx = itemIndex++;
    const div = document.createElement('div');
    div.className = 'border rounded p-3 mb-3 item-block';
    div.dataset.idx = idx;
    div.innerHTML = `
        <div class="row g-2 align-items-end mb-2">
            <div class="col-md-4">
                <label class="form-label small fw-semibold">Barang</label>
                <div class="input-group input-group-sm">
                    <button type="button" class="btn btn-outline-secondary open-product-catalog" title="Buka katalog barang" aria-label="Buka katalog barang"><i class="bi bi-search"></i></button>
                    <input type="text" class="form-control product-search" list="productSuggestions-${idx}"
                           placeholder="Ketik nama barang..." autocomplete="off" required>
                </div>
                <datalist id="productSuggestions-${idx}">${buildProductSuggestions()}</datalist>
                <select name="items[${idx}][product_id]" class="d-none product-select" tabindex="-1" aria-hidden="true">
                    <option value="">-- Pilih Barang --</option>
                    ${buildProductOptions()}
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Satuan</label>
                <select name="items[${idx}][product_unit_id]" class="form-select form-select-sm product-unit-select">
                    <option value="">Pilih</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Qty</label>
                <input type="number" name="items[${idx}][quantity]" class="form-control form-control-sm qty-input"
                       value="1" min="0.01" step="0.01" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Harga Satuan</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text">Rp</span>
                    <input type="text" name="items[${idx}][unit_price]" class="form-control price-input js-currency-input"
                           value="0" inputmode="numeric" required>
                </div>
            </div>
            <div class="col-md-2 d-flex align-items-end gap-2">
                <div class="flex-grow-1">
                    <label class="form-label small fw-semibold">Subtotal</label>
                    <input type="text" class="form-control form-control-sm item-subtotal text-end fw-bold"
                           value="0" readonly data-value="0">
                </div>
                <button type="button" class="btn btn-sm btn-outline-danger remove-item"><i class="bi bi-trash"></i></button>
            </div>
        </div>
        <div class="source-section mt-2 d-none">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="text-muted small fw-semibold">Sumber Stok:</span>
                <span class="source-label badge bg-secondary">-</span>
            </div>
            <div class="source-content"></div>
        </div>
    `;
    document.getElementById('itemsContainer').appendChild(div);
    bindItemEvents(div, idx);
    initializeCurrencyInputs(div);
    if (reveal) {
        requestAnimationFrame(() => div.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
    }
    return div;
}

function bindItemEvents(div, idx) {
    const productSel  = div.querySelector('.product-select');
    const productSearch = div.querySelector('.product-search');
    const unitSel     = div.querySelector('.product-unit-select');
    const qtyInput    = div.querySelector('.qty-input');
    const priceInput  = div.querySelector('.price-input');
    const subtotalEl  = div.querySelector('.item-subtotal');

    function calcSubtotal() {
        const qty   = parseFloat(qtyInput.value) || 0;
        const price = parseCurrency(priceInput.value);
        const sub   = qty * price;
        subtotalEl.value = formatNumber(sub);
        subtotalEl.dataset.value = sub;
        const singleSourceQty = div.querySelector('.source-qty-input');
        if (singleSourceQty) singleSourceQty.value = qty;
        updateTotal();
    }

    qtyInput.addEventListener('input', calcSubtotal);
    priceInput.addEventListener('input', calcSubtotal);

    div.querySelector('.open-product-catalog').addEventListener('click', function() {
        activeCatalogRow = div;
        activeCatalogCategory = 'all';
        document.getElementById('catalogSearch').value = '';
        renderCatalogCategories();
        renderCatalogProducts();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('productCatalogModal')).show();
        document.getElementById('productCatalogModal').addEventListener('shown.bs.modal', () => {
            document.getElementById('catalogSearch').focus();
        }, { once: true });
    });

    function selectProductFromSearch() {
        const query = productSearch.value.trim().toLowerCase();
        const product = products.find(p =>
            String(p.name).trim().toLowerCase() === query ||
            String(p.barcode || '').trim().toLowerCase() === query
        );

        productSel.value = product ? product.id : '';
        productSearch.setCustomValidity(product ? '' : 'Pilih barang dari daftar pencarian.');

        if (product) {
            productSel.dispatchEvent(new Event('change', { bubbles: true }));
        } else {
            updateItemCounter();
        }
    }

    productSearch.addEventListener('input', selectProductFromSearch);
    productSearch.addEventListener('change', selectProductFromSearch);

    productSel.addEventListener('change', function() {
        const product = products.find(p => p.id == this.value);
        if (!product) return;
        productSearch.value = product.name;
        productSearch.setCustomValidity('');
        unitSel.innerHTML = product.product_units.map(unit =>
            `<option value="${unit.id}" ${unit.is_default_sale ? 'selected' : ''}>${escapeHtml(unit.unit_name)}</option>`
        ).join('');
        const unit = selectedProductUnit(div, product);
        priceInput.value = formatNumber(unit ? unit.sell_price : product.sell_price_wholesale);
        calcSubtotal();
        renderSource(div, idx, product, 'unit');
        updateItemCounter();
    });

    unitSel.addEventListener('change', function() {
        const product = products.find(p => p.id == productSel.value);
        if (!product) return;
        const unit = selectedProductUnit(div, product);
        priceInput.value = formatNumber(unit ? unit.sell_price : 0);
        calcSubtotal();
        renderSource(div, idx, product, 'unit');
    });

    div.querySelector('.remove-item').addEventListener('click', function() {
        div.remove();
        updateTotal();
        updateItemCounter();
    });
}

document.getElementById('addItem').addEventListener('click', addItem);

function normalizeOldList(value) {
    if (!value) return [];
    return Array.isArray(value) ? value : Object.values(value);
}

function restoreItemFromOld(div, item) {
    const productSel = div.querySelector('.product-select');
    const unitSel = div.querySelector('.product-unit-select');
    const qtyInput = div.querySelector('.qty-input');
    const priceInput = div.querySelector('.price-input');

    if (!item.product_id) return;

    productSel.value = item.product_id;
    productSel.dispatchEvent(new Event('change', { bubbles: true }));

    if (item.product_unit_id) {
        unitSel.value = item.product_unit_id;
        unitSel.dispatchEvent(new Event('change', { bubbles: true }));
    }

    qtyInput.value = item.quantity || 1;
    priceInput.value = formatNumber(item.unit_price || 0);
    qtyInput.dispatchEvent(new Event('input', { bubbles: true }));
    priceInput.dispatchEvent(new Event('input', { bubbles: true }));

    const product = products.find(p => p.id == item.product_id);
    if (!product) return;

    const sources = normalizeOldList(item.sources);
    const positiveSources = sources.filter(source => Number(source.quantity || 0) > 0);
    const locSelect = div.querySelector('.source-location-select');
    if (!locSelect) return;

    if (positiveSources.length > 1) {
        locSelect.value = 'manual';
        locSelect.dispatchEvent(new Event('change', { bubbles: true }));
        sources.forEach(source => {
            const manualLocation = [...div.querySelectorAll('.manual-location-id')]
                .find(input => input.value == source.location_id);
            if (!manualLocation) return;
            const manualQty = manualLocation.closest('.d-flex').querySelector('.manual-qty');
            manualQty.value = source.quantity || 0;
        });
    } else if (positiveSources.length === 1) {
        locSelect.value = positiveSources[0].location_id;
        locSelect.dispatchEvent(new Event('change', { bubbles: true }));
        const singleQty = div.querySelector('.source-qty-input');
        if (singleQty) singleQty.value = qtyInput.value || 0;
    }
}

const oldItemList = normalizeOldList(oldItems);
if (oldItemList.length) {
    oldItemList.forEach(item => restoreItemFromOld(addItem(false), item));
} else {
    addItem(false);
}

function renderCatalogCategories() {
    const categories = [...new Map(products.map(product => [
        String(product.category_id ?? 'uncategorized'),
        product.category_name || 'Tanpa Kategori'
    ])).entries()];

    document.getElementById('catalogCategories').innerHTML = [
        `<button type="button" class="nav-link active" data-category="all">Semua</button>`,
        ...categories.map(([id, name]) =>
            `<button type="button" class="nav-link" data-category="${escapeHtml(id)}">${escapeHtml(name)}</button>`
        )
    ].join('');
}

function renderCatalogProducts() {
    const query = document.getElementById('catalogSearch').value.trim().toLowerCase();
    const filtered = products.filter(product => {
        const categoryId = String(product.category_id ?? 'uncategorized');
        const matchesCategory = activeCatalogCategory === 'all' || categoryId === activeCatalogCategory;
        const matchesSearch = !query || product.name.toLowerCase().includes(query) ||
            String(product.barcode || '').toLowerCase().includes(query);
        return matchesCategory && matchesSearch;
    });

    document.getElementById('catalogProducts').innerHTML = filtered.map(product => {
        const stock = product.stocks.reduce((total, item) => total + Number(item.quantity || 0), 0);
        return `
            <div class="col-md-6 col-lg-4">
                <button type="button" class="catalog-product w-100" data-product-id="${escapeHtml(product.id)}">
                    <div class="d-flex justify-content-between gap-2 mb-2">
                        <span class="product-name">${escapeHtml(product.name)}</span>
                        <span class="badge bg-light text-secondary border">${escapeHtml(product.category_name || 'Tanpa Kategori')}</span>
                    </div>
                    <div class="small text-muted mb-2">${product.barcode ? `Barcode: ${escapeHtml(product.barcode)}` : 'Tanpa barcode'}</div>
                    <div class="d-flex justify-content-between align-items-end">
                        <div class="small"><span class="text-muted">Harga grosir</span><br><strong>Rp ${formatNumber(product.sell_price_wholesale)}</strong></div>
                        <span class="small text-success">Stok: ${escapeHtml(formatStok(stock, product))}</span>
                    </div>
                </button>
            </div>`;
    }).join('');

    document.getElementById('catalogEmpty').classList.toggle('d-none', filtered.length > 0);
}

document.getElementById('catalogSearch').addEventListener('input', renderCatalogProducts);

document.getElementById('catalogCategories').addEventListener('click', function(event) {
    const button = event.target.closest('[data-category]');
    if (!button) return;
    activeCatalogCategory = button.dataset.category;
    this.querySelectorAll('.nav-link').forEach(tab => tab.classList.toggle('active', tab === button));
    renderCatalogProducts();
});

document.getElementById('catalogProducts').addEventListener('click', function(event) {
    const button = event.target.closest('[data-product-id]');
    if (!button || !activeCatalogRow) return;
    const select = activeCatalogRow.querySelector('.product-select');
    select.value = button.dataset.productId;
    select.dispatchEvent(new Event('change', { bubbles: true }));
    bootstrap.Modal.getInstance(document.getElementById('productCatalogModal')).hide();
});

function addProductByBarcode() {
    const scanner = document.getElementById('barcodeScanner');
    const status  = document.getElementById('scannerStatus');
    const code = scanner.value.trim().toLowerCase();
    if (!code) return;

    const product = products.find(p => String(p.barcode || '').toLowerCase() === code);
    if (!product) {
        status.className = 'small scanner-status mt-1 text-danger';
        status.textContent = `Barcode "${scanner.value.trim()}" tidak ditemukan.`;
        scanner.select();
        return;
    }

    let target = [...document.querySelectorAll('.item-block')].find(row => !row.querySelector('.product-select').value);
    if (!target) target = addItem();
    const select = target.querySelector('.product-select');
    select.value = product.id;
    select.dispatchEvent(new Event('change', {bubbles:true}));
    status.className = 'small scanner-status mt-1 text-success';
    status.textContent = `${product.name} berhasil ditambahkan.`;
    scanner.value = '';
    scanner.focus();
}

document.getElementById('barcodeSearch').addEventListener('click', addProductByBarcode);
document.getElementById('barcodeScanner').addEventListener('keydown', function (event) {
    if (event.key === 'Enter') { event.preventDefault(); addProductByBarcode(); }
});
</script>
@endpush
@endsection
