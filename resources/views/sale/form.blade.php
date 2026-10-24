@extends('layouts.app')
@php($editing = isset($sale))
@section('title', $editing ? 'Ubah Transaksi' : 'Transaksi Baru')
@section('page-title', $editing ? 'Ubah Transaksi Penjualan' : 'Transaksi Penjualan Baru')

@push('styles')
<style>
.pos-heading { background:var(--app-header); color:#fff; border-radius:14px; padding:1rem 1.25rem; }
.pos-items-card { border:0; border-radius:14px; box-shadow:0 3px 16px rgba(25,35,55,.07); overflow:visible; }
.pos-items-card .card-header { position:sticky; top:0; z-index:4; }
.pos-items-scroll { max-height:clamp(520px,calc(100vh - 280px),760px); overflow-y:auto; overscroll-behavior:contain; scrollbar-gutter:stable; }
.pos-checkout { position:sticky; top:78px; border:0; border-radius:14px; box-shadow:0 5px 22px rgba(25,35,55,.10); overflow:hidden; }
.pos-total { background:var(--app-header); color:#fff; padding:1.25rem; }
.pos-total #grandTotal { background:transparent; border:0; color:#ffc107; font-size:1.7rem; font-weight:800; padding:0; box-shadow:none; }
.pos-change { background:#ecf9f1; border:1px solid #ccebd8; border-radius:10px; padding:.75rem; }
.pos-change #changeAmount { background:transparent; border:0; color:#198754; font-weight:700; padding:0; box-shadow:none; }
.cart-list-header { position:sticky; top:0; z-index:3; display:grid; grid-template-columns:minmax(230px,1.55fr) minmax(112px,.55fr) minmax(80px,.45fr) minmax(142px,.7fr) minmax(126px,.65fr) 92px; gap:.5rem; padding:.65rem 1rem; background:#f8fafc; border:1px solid #e4e8f0; border-radius:10px; color:#647084; font-size:.72rem; font-weight:800; text-transform:uppercase; }
.item-block { display:grid; grid-template-columns:minmax(230px,1.55fr) minmax(112px,.55fr) minmax(80px,.45fr) minmax(142px,.7fr) minmax(126px,.65fr) 92px; gap:.5rem; align-items:end; border:1px solid #e4e8f0 !important; border-radius:10px !important; background:#fff; box-shadow:0 1px 4px rgba(25,35,55,.025); padding:.75rem 1rem !important; margin-bottom:.5rem !important; }
.item-block:hover { border-color:#c8d0df !important; }
.cart-item-main { display:contents; }
.cart-field label { display:none; }
.cart-actions { display:flex; gap:.35rem; align-items:end; justify-content:flex-end; }
.source-section { grid-column:1 / -1; background:#f8f9fb; border-radius:9px; padding:.65rem .75rem; border:1px dashed #dce1ea; }
.source-section.is-collapsed .source-content { display:none; }
.source-toggle { white-space:nowrap; }
.source-toggle .bi-chevron-up { display:none; }
.source-toggle.is-expanded .bi-chevron-down { display:none; }
.source-toggle.is-expanded .bi-chevron-up { display:inline-block; }
.source-locked { background:#eef8f1; border:1px solid #d5ebdc; border-radius:8px; padding:8px 12px; font-size:.85rem; }
.scanner-box{background:#fff8df;border:1px solid #f1d77d;border-radius:11px;padding:.75rem 1rem}.scanner-box .form-control{border-color:#e7c24d}.scanner-status{min-height:18px}
.catalog-tabs { flex-wrap:nowrap; overflow-x:auto; padding-bottom:.25rem; scrollbar-width:thin; }
.catalog-tabs .nav-link { white-space:nowrap; color:#566078; border:1px solid #e2e6ed; background:#fff; }
.catalog-tabs .nav-link.active { color:#fff; background:var(--app-accent-2); border-color:var(--app-accent-2); }
.catalog-list { max-height:55vh; overflow-y:auto; overflow-x:hidden; }
.catalog-product { height:100%; border:1px solid #e2e6ed; border-radius:11px; background:#fff; padding:.85rem; text-align:left; transition:.15s ease; }
.catalog-product:hover { border-color:#198754; box-shadow:0 4px 14px rgba(25,135,84,.12); transform:translateY(-1px); }
.catalog-product .product-name { color:#172039; font-weight:700; }
.product-search-wrap { position:relative; }
.pos-items-scroll.product-search-open { overflow:visible; }
.product-results { position:absolute; top:100%; left:0; right:0; z-index:1055; max-height:260px; overflow-y:auto; margin-top:.25rem; border:1px solid #d7deea; border-radius:8px; background:#fff; box-shadow:0 10px 26px rgba(15,23,42,.16); }
.product-result { width:100%; border:0; border-bottom:1px solid #eef1f6; background:#fff; padding:.65rem .75rem; text-align:left; }
.product-result:last-child { border-bottom:0; }
.product-result:hover, .product-result:focus { background:#fff8df; outline:0; }
.product-result-name { display:block; color:#172039; font-weight:700; }
.product-result-meta { display:block; color:#6b7280; font-size:.78rem; margin-top:.15rem; }
.product-results-empty { padding:.7rem .75rem; color:#6b7280; font-size:.85rem; }
@media(max-width:1199px){.pos-checkout{position:static}.pos-items-card .card-header{position:static}.pos-items-scroll{max-height:none;overflow:visible}.cart-list-header{display:none}.item-block{display:block}.cart-item-main{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}.cart-field label{display:block}.cart-actions{justify-content:flex-start}.source-section{margin-top:.75rem}}
@media(max-width:575px){.cart-item-main{grid-template-columns:1fr}.cart-actions{justify-content:space-between}.cart-actions .btn{flex:1}}
.pos-workspace { display:grid; grid-template-columns:minmax(0,1fr) 320px; gap:1rem; align-items:start; }
.pos-workspace > * { min-width:0; }
.pos-entry { position:relative; padding:.75rem; background:#fff; border:1px solid var(--app-border); border-radius:8px; }
.pos-entry .product-results { left:.75rem; right:.75rem; }
.pos-entry-controls { display:flex; gap:.5rem; }
.pos-entry-controls .input-group { min-width:0; flex:1; }
.pos-cart-toolbar { display:flex; align-items:center; justify-content:space-between; gap:.75rem; padding:.65rem .75rem; }
.pos-cart-toolbar input { width:210px; max-width:100%; }
.pos-items-scroll { height:calc(100dvh - 360px); min-height:280px; max-height:none; padding:0 .5rem .5rem; overflow:auto; }
.pos-items-scroll.product-search-open { overflow:auto; }
.cart-list-header, .item-block { grid-template-columns:minmax(165px,1fr) 100px 70px 120px 110px 68px; gap:.4rem; }
.cart-list-header { padding:.55rem .5rem; margin-bottom:0 !important; border-radius:0; background:#eef2ec; }
.item-block { padding:.4rem .5rem !important; margin:0 !important; border:0 !important; border-bottom:1px solid var(--app-border) !important; border-radius:0 !important; box-shadow:none; align-items:center; }
.item-block:nth-child(even) { background:#fbfcfa; }
.item-block:focus-within { background:#edf6f0; }
.cart-actions .btn { width:30px; height:30px; padding:0; flex:none; }
.cart-actions .source-toggle .bi-chevron-down, .cart-actions .source-toggle .bi-chevron-up { display:none; }
.source-section { margin:0; padding:.4rem .5rem; border-radius:4px; }
.source-section.is-collapsed { display:none; }
.pos-checkout { top:76px; }
.pos-checkout .card-body { padding:.85rem; }
.pos-checkout .pos-total { padding:.75rem .85rem; }
.pos-checkout .mb-3 { margin-bottom:.7rem !important; }
.pos-checkout .btn-group .btn { font-size:.8rem; padding:.5rem .3rem; white-space:normal; }
.cart-empty { padding:2rem 1rem; text-align:center; color:var(--app-muted); }
.stock-warning { grid-column:1 / -1; color:#a12622; background:#fff2f0; border-left:3px solid #c03932; padding:.4rem .6rem; font-size:.8rem; }
.item-block.has-stock-error { background:#fff9f8; }
.stock-overview { padding:.55rem .75rem; background:#fff2f0; color:#a12622; border-bottom:1px solid #edcbc7; font-size:.82rem; }
.product-result.is-active { background:#edf6f0; outline:2px solid #1f7a5a; outline-offset:-2px; }
@media(min-width:1200px) and (max-width:1450px) { .pos-workspace { grid-template-columns:minmax(0,1fr) 285px; } .cart-list-header,.item-block { grid-template-columns:minmax(130px,1fr) 100px 58px 110px 100px 64px; } }
@media(max-width:1199px) { .pos-workspace { grid-template-columns:minmax(0,1fr); } .pos-items-scroll { height:55dvh; min-height:300px; overflow:auto; } .pos-checkout { position:static; } .item-block { display:grid; } .cart-item-main { display:contents; } .cart-field label { display:none; } .cart-list-header { display:grid; } .cart-list-header,.item-block { min-width:700px; } .cart-actions { justify-content:flex-end; } }
@media(max-width:575px) { .pos-entry-controls { flex-wrap:wrap; } .pos-entry-controls .input-group { flex-basis:100%; } .pos-cart-toolbar { flex-wrap:wrap; } .pos-cart-toolbar input { width:100%; } .pos-items-scroll { height:50dvh; } }
</style>
@endpush

@section('content')
<form method="POST" action="{{ $editing ? route('sale.sales.update', $sale) : route('sale.sales.store') }}" id="saleForm">
    @csrf
    <input type="hidden" name="draft_id" id="saleDraftId" value="{{ old('draft_id') }}">
    <input type="hidden" name="draft_version" id="saleDraftVersion" value="{{ old('draft_version', 0) }}">
    @if($editing) @method('PUT') @endif
    <div class="pos-heading d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <div><h5 class="fw-bold mb-0"><i class="bi bi-cart3 text-warning me-2"></i>{{ $editing ? 'Ubah '.$sale->sale_number : 'Transaksi Penjualan' }}</h5></div>
        <div class="d-flex align-items-center flex-wrap gap-2"><span class="small text-white-50" id="draftSaveStatus" role="status" aria-live="polite">Belum ada draft</span><button type="button" class="btn btn-sm btn-outline-light" id="showSaleDrafts"><i class="bi bi-clock-history me-1"></i>Draf</button><span class="badge bg-warning text-dark" id="itemCounter">0 Barang</span></div>
    </div>
    <div class="pos-workspace">
        <div>
            <div class="pos-entry mb-2">
                <div class="pos-entry-controls"><div class="input-group"><span class="input-group-text bg-white"><i class="bi bi-upc-scan"></i></span><input type="text" id="barcodeScanner" class="form-control" autocomplete="off" placeholder="Nama barang atau barcode" aria-label="Cari barang atau scan barcode" aria-controls="entryResults" aria-expanded="false"><button type="button" id="barcodeSearch" class="btn btn-primary" title="Tambahkan barang" aria-label="Tambahkan barang"><i class="bi bi-plus-lg"></i></button></div><button type="button" class="btn btn-outline-secondary" id="openCatalog"><i class="bi bi-grid me-1"></i>Katalog</button></div>
                <div id="entryResults" class="product-results d-none"></div>
                <div class="d-flex flex-wrap align-items-center gap-2 mt-2"><label for="defaultSourceLocation" class="small text-muted">Lokasi barang baru</label><select id="defaultSourceLocation" class="form-select form-select-sm" style="width:155px"></select><div id="scannerStatus" class="small scanner-status" role="status" aria-live="polite"></div></div>
            </div>
            <div class="card pos-items-card">
                <div class="card-header bg-white pos-cart-toolbar">
                    <div><h6 class="fw-bold mb-0">Keranjang <span class="text-muted small" id="cartCount">0 jenis</span></h6><span class="small text-muted" id="cartFilterCount"></span></div>
                    <input type="search" id="cartSearch" class="form-control form-control-sm" placeholder="Cari di keranjang" aria-label="Cari barang di keranjang">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="addItem" title="Tambah baris barang" aria-label="Tambah baris barang"><i class="bi bi-plus-lg"></i></button>
                </div>
                <div id="stockOverview" class="stock-overview d-none" role="status" aria-live="polite"><span id="stockOverviewText"></span><button type="button" id="reviewStock" class="btn btn-sm btn-outline-danger ms-2">Periksa Stok</button></div>
                <div class="card-body pt-1 pos-items-scroll">
                    <div class="cart-list-header mb-2" aria-hidden="true">
                        <span>Barang</span>
                        <span>Satuan</span>
                        <span>Qty</span>
                        <span>Harga (Rp)</span>
                        <span>Subtotal (Rp)</span>
                        <span class="text-end">Aksi</span>
                    </div>
                    <div id="itemsContainer"></div>
                    <div id="cartEmpty" class="cart-empty">Keranjang kosong</div>
                </div>
            </div>
        </div>
        <div>
            <div class="card pos-checkout">
                <div class="pos-total"><div class="small text-white-50 text-uppercase mb-1">Total Pembayaran</div><div class="d-flex align-items-center"><span class="fs-5 me-2">Rp</span><input type="text" id="grandTotal" class="form-control" value="0" readonly></div></div>
                <div class="card-body">
                    <h6 class="fw-bold mb-3"><i class="bi bi-receipt me-2 text-primary"></i>Informasi Transaksi</h6>
                    <div class="row g-2 mb-3">
                        <div class="col-6"><label class="form-label small fw-semibold">Tanggal <span class="text-danger">*</span></label><input type="date" name="sale_date" class="form-control form-control-sm @error('sale_date') is-invalid @enderror" value="{{ old('sale_date', $editing ? $sale->sale_date->format('Y-m-d') : now('Asia/Jakarta')->toDateString()) }}" max="{{ now('Asia/Jakarta')->toDateString() }}" required>@error('sale_date')<div class="invalid-feedback">{{ $message }}</div>@enderror @if($editing)<div class="small text-muted mt-1">{{ $sale->transaction_time_label }}</div>@endif</div>
                        <div class="col-6"><label class="form-label small fw-semibold">Pembayaran <span class="text-danger">*</span></label><select name="payment_method" class="form-select form-select-sm" required><option value="tunai" @selected(old('payment_method', $editing ? $sale->payment_method : 'tunai') === 'tunai')>Tunai</option><option value="transfer" @selected(old('payment_method', $editing ? $sale->payment_method : 'tunai') === 'transfer')>Transfer</option></select></div>
                        <div class="col-12"><label class="form-label small fw-semibold">Nama Pelanggan</label><input type="text" name="customer_name" class="form-control form-control-sm" value="{{ old('customer_name', $editing ? $sale->customer_name : '') }}" placeholder="Pelanggan umum (opsional)"></div>
                    </div>
                    @if($editing)
                    <input type="hidden" name="sale_status" value="pending">
                    <div class="alert alert-warning py-2 small">Status: <strong>Menunggu Pembayaran</strong>. Batas pembayaran {{ $sale->hold_until->format('H:i') }} WIB.</div>
                    @else
                    <label class="form-label small fw-semibold">Status Transaksi</label>
                    <div class="btn-group w-100 mb-3" role="group" aria-label="Status transaksi">
                        <input type="radio" class="btn-check" name="sale_status" id="salePaid" value="paid" @checked(old('sale_status', 'paid') === 'paid')>
                        <label class="btn btn-outline-success" for="salePaid">Bayar Sekarang</label>
                        <input type="radio" class="btn-check" name="sale_status" id="salePending" value="pending" @checked(old('sale_status') === 'pending')>
                        <label class="btn btn-outline-warning" for="salePending">Menunggu Pembayaran</label>
                    </div>
                    @endif
                    <div class="small text-muted mb-3" id="pendingNotice" hidden>Stok langsung berkurang. Jika belum dibayar pukul 18.00 WIB, transaksi dibatalkan otomatis dan stok dikembalikan.</div>
                    <label class="form-label small fw-semibold">Jumlah Bayar <span class="text-danger">*</span></label>
                    <div class="input-group mb-3"><span class="input-group-text">Rp</span><input type="text" name="amount_paid" id="amountPaid" class="form-control fw-semibold js-currency-input" value="{{ number_format((float) old('amount_paid', 0), 0, ',', '.') }}" inputmode="numeric" required><button type="button" id="exactPayment" class="btn btn-outline-success" title="Isi pembayaran sejumlah total">Uang Pas</button></div>
                    <div class="pos-change d-flex justify-content-between align-items-center mb-3"><div><div class="small text-muted">Kembalian</div><div class="small text-success"><i class="bi bi-check-circle me-1"></i>Hitung otomatis</div></div><div class="d-flex align-items-center"><span class="text-success me-1">Rp</span><input type="text" id="changeAmount" class="form-control text-end" value="0" readonly style="width:120px"></div></div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="transaction_confirmed" value="1" id="transactionConfirmed" required>
                        <label class="form-check-label small" for="transactionConfirmed" id="transactionConfirmationLabel">Saya sudah memeriksa transaksi ini</label>
                    </div>
                    @error('transaction_confirmed')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
                    <div class="text-danger small mb-2 d-none" id="salePaymentValidation">Jumlah bayar harus mencukupi total transaksi.</div>
                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold" id="saveSaleButton" disabled><i class="bi bi-check-circle me-1"></i>{{ $editing ? 'Simpan Perubahan' : 'Simpan Transaksi' }}</button>
                    <a href="{{ $editing ? route('sale.sales.show', $sale) : route('sale.sales.index') }}" class="btn btn-outline-secondary w-100 mt-2">Batal</a>
                </div>
            </div>
        </div>
    </div>
</form>

<div class="modal" id="saleDraftModal" tabindex="-1" aria-labelledby="saleDraftTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="saleDraftTitle">Keranjang Belum Selesai</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
        <div class="modal-body"><div id="saleDraftMessage" class="small text-muted mb-2"></div><div id="saleDraftList"></div></div>
        <div class="modal-footer"><button type="button" id="startNewSaleDraft" class="btn btn-outline-secondary"><i class="bi bi-plus-lg me-1"></i>{{ $editing ? 'Kembali ke Transaksi' : 'Keranjang Baru' }}</button></div>
    </div></div>
</div>

<div class="modal fade" id="productCatalogModal" tabindex="-1" aria-labelledby="productCatalogTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold" id="productCatalogTitle"><i class="bi bi-grid me-2 text-primary"></i>Pilih Barang</h5>
                    <div class="small text-muted" id="catalogCartCount">0 jenis di keranjang</div>
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
            <div class="modal-footer"><button type="button" class="btn btn-primary" data-bs-dismiss="modal"><i class="bi bi-check-lg me-1"></i>Selesai</button></div>
        </div>
    </div>
</div>

@push('scripts')
<script>
const products  = {!! json_encode($productsJson) !!};
const locations = {!! json_encode($locationsJson) !!};
const oldItems  = @json(old('items', $initialItems ?? []));
const reservedItems = @json($initialItems ?? []);

const tokoLocation = locations.find(l => l.name.toLowerCase() === 'toko') || locations[0];
document.getElementById('defaultSourceLocation').innerHTML = locations.map(location =>
    `<option value="${escapeHtml(location.id)}" ${location.id == tokoLocation?.id ? 'selected' : ''}>${escapeHtml(location.name)}</option>`
).join('');

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
    updateSaveState();
    document.dispatchEvent(new Event('sale-cart-changed'));
}

function updateItemCounter() {
    const count = new Set([...document.querySelectorAll('.product-select')].filter(select => select.value).map(select => select.value)).size;
    document.getElementById('itemCounter').textContent = `${count} Barang`;
    document.getElementById('cartCount').textContent = `${count} jenis`;
    document.getElementById('catalogCartCount').textContent = `${count} jenis di keranjang`;
    filterCart();
}

function filterCart() {
    const query = document.getElementById('cartSearch').value.trim().toLowerCase();
    const rows = [...document.querySelectorAll('#itemsContainer .item-block')];
    let visible = 0;
    rows.forEach(row => {
        const product = products.find(p => p.id == row.querySelector('.product-select').value);
        const matches = !query || !product || `${product.name} ${product.barcode || ''}`.toLowerCase().includes(query);
        row.classList.toggle('d-none', !matches);
        if (matches) visible++;
    });
    document.getElementById('cartFilterCount').textContent = query ? `${visible} dari ${rows.length} baris` : '';
    const empty = document.getElementById('cartEmpty');
    empty.hidden = visible > 0;
    empty.textContent = rows.length ? 'Barang tidak ditemukan di keranjang' : 'Keranjang kosong';
}
document.getElementById('cartSearch').addEventListener('input', filterCart);
document.getElementById('exactPayment').addEventListener('click', () => {
    if (document.getElementById('amountPaid').readOnly) return;
    document.getElementById('amountPaid').value = document.getElementById('grandTotal').value;
    updateTotal();
});

document.getElementById('amountPaid').addEventListener('input', updateTotal);
function updateSaleStatus() {
    const pendingInput = document.getElementById('salePending');
    const pending = pendingInput ? pendingInput.checked : true;
    const amount = document.getElementById('amountPaid');
    document.getElementById('pendingNotice').hidden = !pending;
    amount.readOnly = pending;
    document.getElementById('exactPayment').disabled = pending;
    if (pending) amount.value = '0';
    const label = document.getElementById('transactionConfirmationLabel');
    label.textContent = @json($editing)
        ? 'Saya sudah memeriksa perubahan transaksi ini'
        : (pending ? 'Simpan sebagai menunggu pembayaran dan kurangi stok' : 'Pembayaran sudah diterima dari pelanggan');
    document.getElementById('transactionConfirmed').checked = false;
    updateTotal();
}
document.querySelectorAll('input[name="sale_status"]').forEach(input => input.addEventListener('change', updateSaleStatus));
document.getElementById('transactionConfirmed').addEventListener('change', updateSaveState);

function updateSaveState() {
    const stockValid = validateCartStock();
    const pendingInput = document.getElementById('salePending');
    const pending = pendingInput ? pendingInput.checked : true;
    const total = [...document.querySelectorAll('.item-subtotal')]
        .reduce((sum, el) => sum + Number(el.dataset.value || 0), 0);
    const paid = parseCurrency(document.getElementById('amountPaid').value);
    const enough = pending || (total > 0 && paid >= total);
    const confirmed = document.getElementById('transactionConfirmed').checked;
    document.getElementById('salePaymentValidation').classList.toggle('d-none', pending || paid === 0 || enough);
    document.getElementById('saveSaleButton').disabled = !confirmed || !enough || total <= 0 || !stockValid;
}

function rowStockSources(row) {
    return [...row.querySelectorAll('input[name*="[sources]"][name$="[location_id]"]:not(:disabled)')].map(input => {
        const qty = row.querySelector(`input[name="${input.name.replace('[location_id]', '[quantity]')}"]:not(:disabled)`);
        return { locationId: String(input.value), quantity: Number(qty?.value || 0) };
    });
}

function validateCartStock() {
    const rows = [...document.querySelectorAll('#itemsContainer .item-block')];
    const demand = new Map();
    const reserved = new Map();
    const key = (productId, locationId) => `${productId}:${locationId}`;
    // Pending sales already deducted stock; only their original allocations are credited when editing.
    reservedItems.forEach(item => {
        const product = products.find(p => p.id == item.product_id);
        const unit = product?.product_units.find(u => u.id == item.product_unit_id);
        if (!unit) return;
        (item.sources || []).forEach(source => {
            const sourceKey = key(item.product_id, source.location_id);
            reserved.set(sourceKey, (reserved.get(sourceKey) || 0) + Number(source.quantity) * Number(unit.conversion_to_base));
        });
    });
    const entries = rows.map(row => {
        const product = products.find(p => p.id == row.querySelector('.product-select').value);
        const unit = product ? selectedProductUnit(row, product) : null;
        const sources = rowStockSources(row);
        const conversion = Number(unit?.conversion_to_base || 0);
        if (product && conversion > 0) sources.forEach(source => {
            const sourceKey = key(product.id, source.locationId);
            demand.set(sourceKey, (demand.get(sourceKey) || 0) + Math.max(0, source.quantity) * conversion);
        });
        return { row, product, unit, sources, conversion };
    });
    const available = (product, locationId) => Number(product.stocks.find(s => s.location_id == locationId)?.quantity || 0) + (reserved.get(key(product.id, locationId)) || 0);
    const number = value => Number(value).toLocaleString('id-ID', { maximumFractionDigits: 2 });
    let invalidCount = 0;
    entries.forEach(({ row, product, unit, sources, conversion }) => {
        const messages = [];
        const qtyInput = row.querySelector('.qty-input');
        const quantity = Number(qtyInput.value || 0);
        if (!product && row.dataset.missingProduct) {
            messages.push('Barang dalam draft sudah tidak tersedia. Hapus atau pilih barang pengganti.');
        }
        if (product) {
            if (unit && conversion > 0) {
                row.querySelectorAll('.source-location-select option').forEach(option => {
                    const location = locations.find(l => l.id == option.value);
                    if (!location) return;
                    const stock = available(product, location.id);
                    option.dataset.qty = stock;
                    option.textContent = `${location.name} (Stok: ${formatStok(stock, product)})`;
                });
                const selectedLocation = row.querySelector('.source-location-select')?.value;
                const stockLabel = row.querySelector('.available-qty');
                if (stockLabel && selectedLocation !== 'manual') {
                    stockLabel.textContent = formatStok(available(product, selectedLocation), product);
                }
                row.querySelectorAll('.manual-stock').forEach(label => {
                    label.textContent = formatStok(available(product, label.dataset.locationId), product);
                });
            }
            const allStock = locations.reduce((sum, location) => sum + available(product, location.id), 0);
            if (!unit || conversion <= 0 || !product.product_units.some(u => u.id == row.querySelector('.product-unit-select').value)) {
                messages.push('Satuan barang belum valid.');
            } else if (allStock <= 0) {
                messages.push('Stok layak jual kosong di semua lokasi.');
            } else {
                sources.filter(source => source.quantity > 0).forEach(source => {
                    const stock = available(product, source.locationId);
                    const requested = demand.get(key(product.id, source.locationId)) || 0;
                    if (requested > stock + 0.000001) {
                        const location = locations.find(l => l.id == source.locationId);
                        messages.push(`${location?.name || 'Lokasi'}: ${stock <= 0 ? 'stok kosong' : `stok hanya ${formatStok(stock, product)}`}. Total kebutuhan di keranjang ${number(requested / conversion)} ${unit.unit_name}.`);
                    }
                });
                if (messages.length) {
                    const alternatives = locations.filter(l => !sources.some(s => s.quantity > 0 && s.locationId == l.id)).map(l => {
                        const remaining = available(product, l.id) - (demand.get(key(product.id, l.id)) || 0);
                        return { location: l, quantity: remaining };
                    }).filter(s => s.quantity > 0);
                    if (alternatives.length) messages.push(`Sisa di lokasi lain: ${alternatives.map(s => `${s.location.name} ${formatStok(s.quantity, product)}`).join('; ')}.`);
                }
            }
            const allocated = sources.reduce((sum, source) => sum + source.quantity, 0);
            if (!sources.length || sources.some(source => !locations.some(l => l.id == source.locationId))) messages.push('Pilih sumber stok yang valid.');
            if (sources.some(source => !Number.isFinite(source.quantity) || source.quantity < 0) || Math.abs(allocated - quantity) > 0.000001) {
                messages.push(`Jumlah sumber stok ${number(allocated)} harus sama dengan qty pembelian ${number(quantity)} ${unit?.unit_name || ''}.`);
            }
        }
        const message = messages.join(' ');
        const warning = row.querySelector('.stock-warning');
        warning.textContent = message;
        warning.classList.toggle('d-none', !message);
        row.classList.toggle('has-stock-error', Boolean(message));
        qtyInput.setCustomValidity(message);
        if (message) invalidCount++;
    });
    document.getElementById('stockOverview').classList.toggle('d-none', invalidCount === 0);
    document.getElementById('stockOverviewText').textContent = `${invalidCount} baris bermasalah dengan stok. Perbaiki sebelum menyimpan transaksi.`;
    return invalidCount === 0 && entries.every(entry => entry.product);
}

document.getElementById('reviewStock').addEventListener('click', () => {
    document.getElementById('cartSearch').value = '';
    filterCart();
    const row = document.querySelector('.has-stock-error');
    if (!row) return;
    row.querySelector('.source-section').classList.remove('is-collapsed');
    row.querySelector('.source-toggle').classList.add('is-expanded');
    row.querySelector('.source-toggle').setAttribute('aria-expanded', 'true');
    row.scrollIntoView({ block: 'nearest' });
    (row.querySelector('.source-location-select') || row.querySelector('.qty-input')).focus({ preventScroll: true });
});

document.getElementById('saleForm').addEventListener('submit', function (event) {
    updateSaveState();
    const button = document.getElementById('saveSaleButton');
    if (button.disabled) event.preventDefault();
});

function confirmSaleSubmission() {
    const pendingInput = document.getElementById('salePending');
    const pending = pendingInput ? pendingInput.checked : true;
    const message = @json($editing)
        ? 'Simpan perubahan transaksi ini? Stok akan dihitung ulang.'
        : (pending
            ? 'Simpan sebagai menunggu pembayaran? Stok akan langsung dikurangi.'
            : 'Pastikan pembayaran sudah diterima. Simpan transaksi ini?');

    const details = [['Total Transaksi', 'Rp ' + document.getElementById('grandTotal').value]];
    if (!pending) details.push(['Jumlah Bayar', 'Rp ' + document.getElementById('amountPaid').value], ['Kembalian', 'Rp ' + document.getElementById('changeAmount').value]);
    return appDialog.confirm({ title: @json($editing) ? 'Simpan Perubahan?' : (pending ? 'Simpan Transaksi Menunggu Pembayaran?' : 'Simpan Transaksi?'), text: message, confirmText: @json($editing) ? 'Simpan Perubahan' : 'Simpan Transaksi', details });
}
document.getElementById('saleForm').addEventListener('invalid', event => {
    const row = event.target.closest('.item-block');
    if (!row) return;
    document.getElementById('cartSearch').value = '';
    filterCart();
    if (event.target.closest('.source-section')) {
        row.querySelector('.source-section').classList.remove('is-collapsed');
        const toggle = row.querySelector('.source-toggle');
        toggle.classList.add('is-expanded');
        toggle.setAttribute('aria-expanded', 'true');
    }
    row.scrollIntoView({ block: 'nearest' });
}, true);
updateSaleStatus();

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    })[char]);
}

function buildProductOptions() {
    return products.map(p => `<option value="${escapeHtml(p.id)}">${escapeHtml(p.name)}</option>`).join('');
}

function formatStok(rawQty, product) {
    const quantityNumber = value => Number(value).toLocaleString('id-ID', { maximumFractionDigits: 4 });
    if (product && product.product_units && product.product_units.length) {
        const units = [...product.product_units]
            .filter(unit => Number(unit.conversion_to_base) > 0)
            .sort((a, b) => b.conversion_to_base - a.conversion_to_base);
        let remaining = Math.round(Math.abs(rawQty) * 10000);
        const parts = [];
        units.forEach((unit, index) => {
            const conversion = Math.round(Number(unit.conversion_to_base) * 10000);
            if (conversion <= 0) return;
            const amount = index === units.length - 1
                ? remaining / conversion
                : Math.floor(remaining / conversion);
            if (amount > 0) {
                parts.push(`${quantityNumber(amount)} ${unit.unit_name}`);
                remaining = remaining % conversion;
            }
        });
        if (parts.length) return `${rawQty < 0 ? '-' : ''}${parts.join(' ')}`;
        if (units.length) return `0 ${units[units.length - 1].unit_name}`;
    }

    if (product && product.can_retail && product.conversion_factor > 0) {
        const wholeDus  = Math.floor(rawQty / product.conversion_factor);
        const sisaBotol = Math.round((rawQty - wholeDus * product.conversion_factor) * 10000) / 10000;
        return sisaBotol > 0
            ? `${quantityNumber(wholeDus)} ${product.buy_unit_name} ${quantityNumber(sisaBotol)} ${product.retail_unit_name}`
            : `${quantityNumber(wholeDus)} ${product.buy_unit_name}`;
    }
    return `${quantityNumber(rawQty)} ${product ? product.buy_unit_name : ''}`;
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
    const rawQty      = parseFloat(selectedOpt?.dataset.qty || 0);
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

function updateSourceSummary(div) {
    const summary = div.querySelector('.source-summary');
    const locSelect = div.querySelector('.source-location-select');
    const unit = div.querySelector('.product-unit-select').selectedOptions[0]?.textContent || '';
    const qty = div.querySelector('.qty-input').value || 0;
    if (locSelect?.value === 'manual') {
        const sources = [...div.querySelectorAll('.manual-qty')]
            .filter(input => Number(input.value) > 0)
            .map(input => {
                const row = input.closest('.d-flex');
                const locationId = row.querySelector('.manual-location-id').value;
                const location = locations.find(loc => loc.id == locationId);
                return `${location?.name || '-'}: ${Number(input.value).toLocaleString('id-ID')} ${unit}`;
            });
        summary.textContent = sources.length ? sources.join(' / ') : 'Belum ada sumber stok dipilih';
        div.querySelector('.source-toggle').title = summary.textContent;
        return;
    }
    const locationId = locSelect ? locSelect.value : tokoLocation?.id;
    const location = locations.find(loc => loc.id == locationId);
    summary.textContent = location
        ? `${location.name}: ${Number(qty).toLocaleString('id-ID')} ${unit}`
        : 'Sumber stok belum tersedia';
    div.querySelector('.source-toggle').title = summary.textContent;
}

function renderSource(div, idx, product, saleType) {
    const section = div.querySelector('.source-section');
    const label   = div.querySelector('.source-label');
    const content = div.querySelector('.source-content');
    const toggle  = div.querySelector('.source-toggle');

    section.classList.remove('d-none');
    toggle.classList.remove('d-none');
    content.oninput = () => { updateSourceSummary(div); updateSaveState(); };

    if (saleType === 'ecer') {
        if (!tokoLocation) {
            content.innerHTML = `<div class="alert alert-danger py-1 small">Lokasi "Toko" tidak ditemukan.</div>`;
            updateSourceSummary(div);
            updateSaveState();
            document.dispatchEvent(new Event('sale-cart-changed'));
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

        const previousLocation = div.querySelector('.source-location-select')?.value;
        const defaultLocId = previousLocation && previousLocation !== 'manual'
            ? previousLocation
            : document.getElementById('defaultSourceLocation').value;

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
                <span class="text-muted small">Stok: <strong class="manual-stock" data-location-id="${loc.id}">${dQty}</strong></span>
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
            updateSourceSummary(div);
            updateSaveState();
        });
    }
    updateSourceSummary(div);
    updateSaveState();
}

function addItem(reveal = true) {
    if (reveal) {
        document.querySelectorAll('#itemsContainer .item-block').forEach(item => {
            item.querySelector('.source-section').classList.add('is-collapsed');
            const toggle = item.querySelector('.source-toggle');
            toggle.classList.remove('is-expanded');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.title = 'Tampilkan sumber stok';
        });
    }
    const idx = itemIndex++;
    const div = document.createElement('div');
    div.className = 'item-block';
    div.dataset.idx = idx;
    div.innerHTML = `
        <div class="cart-item-main">
            <div class="cart-field">
                <label class="form-label small fw-semibold">Barang</label>
                <div class="product-search-wrap">
                    <div class="input-group input-group-sm">
                        <button type="button" class="btn btn-outline-secondary open-product-catalog" title="Buka katalog barang" aria-label="Buka katalog barang"><i class="bi bi-search"></i></button>
                        <input type="text" class="form-control product-search"
                               placeholder="Ketik nama barang..." aria-label="Nama barang" autocomplete="off" required>
                    </div>
                    <div class="product-results d-none"></div>
                </div>
                <select name="items[${idx}][product_id]" class="d-none product-select" tabindex="-1" aria-hidden="true">
                    <option value="">-- Pilih Barang --</option>
                    ${buildProductOptions()}
                </select>
            </div>
            <div class="cart-field">
                <label class="form-label small fw-semibold">Satuan</label>
                <select name="items[${idx}][product_unit_id]" class="form-select form-select-sm product-unit-select" aria-label="Satuan barang">
                    <option value="">Pilih</option>
                </select>
            </div>
            <div class="cart-field">
                <label class="form-label small fw-semibold">Qty</label>
                <input type="number" name="items[${idx}][quantity]" class="form-control form-control-sm qty-input"
                       value="1" min="0.01" step="0.01" aria-label="Jumlah barang" required>
            </div>
            <div class="cart-field">
                <label class="form-label small fw-semibold">Harga Satuan</label>
                <div class="input-group input-group-sm">
                    <input type="text" name="items[${idx}][unit_price]" class="form-control text-end price-input js-currency-input" aria-label="Harga satuan dalam rupiah"
                           value="0" inputmode="numeric" required>
                </div>
            </div>
            <div class="cart-field">
                <label class="form-label small fw-semibold">Subtotal</label>
                <input type="text" class="form-control form-control-sm item-subtotal text-end fw-bold"
                       value="0" readonly data-value="0">
            </div>
            <div class="cart-actions">
                <button type="button" class="btn btn-sm btn-outline-secondary source-toggle d-none" title="Tampilkan sumber stok" aria-label="Sumber stok" aria-expanded="false">
                    <i class="bi bi-diagram-3 me-1"></i><i class="bi bi-chevron-down"></i><i class="bi bi-chevron-up"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger remove-item" title="Hapus barang" aria-label="Hapus barang"><i class="bi bi-trash"></i></button>
            </div>
        </div>
        <div class="stock-warning d-none"></div>
        <div class="source-section is-collapsed d-none">
            <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                <span class="text-muted small fw-semibold">Sumber Stok:</span>
                <span class="source-label badge bg-secondary">-</span>
                <span class="source-summary small fw-semibold text-break"></span>
            </div>
            <div class="source-content"></div>
        </div>
    `;
    document.getElementById('itemsContainer').appendChild(div);
    bindItemEvents(div, idx);
    initializeCurrencyInputs(div);
    filterCart();
    updateSaveState();
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
    const productResults = div.querySelector('.product-results');

    function calcSubtotal() {
        const qty   = parseFloat(qtyInput.value) || 0;
        const price = parseCurrency(priceInput.value);
        const sub   = qty * price;
        subtotalEl.value = formatNumber(sub);
        subtotalEl.dataset.value = sub;
        const singleSourceQty = div.querySelector('.source-qty-input');
        if (singleSourceQty) singleSourceQty.value = qty;
        updateSourceSummary(div);
        updateTotal();
    }

    function matchingProducts(query) {
        const keyword = query.trim().toLowerCase();
        if (!keyword) return products.slice(0, 8);

        return products.filter(product =>
            String(product.name || '').toLowerCase().includes(keyword) ||
            String(product.barcode || '').toLowerCase().includes(keyword)
        ).slice(0, 8);
    }

    function closeProductResults() {
        productResults.classList.add('d-none');
        productResults.innerHTML = '';
        div.closest('.pos-items-scroll')?.classList.remove('product-search-open');
    }

    div.closest('.pos-items-scroll').addEventListener('scroll', closeProductResults);

    function renderProductResults() {
        const matches = matchingProducts(productSearch.value);
        document.querySelectorAll('.product-results').forEach(results => {
            if (results !== productResults) {
                results.classList.add('d-none');
                results.innerHTML = '';
            }
        });
        div.closest('.pos-items-scroll').classList.add('product-search-open');
        productResults.classList.remove('d-none');
        const bounds = productSearch.closest('.input-group').getBoundingClientRect();
        Object.assign(productResults.style, {
            position: 'fixed', left: `${bounds.left}px`, right: 'auto', width: `${bounds.width}px`,
            top: `${bounds.bottom}px`, maxHeight: `${Math.max(100, Math.min(260, window.innerHeight - bounds.bottom - 12))}px`
        });

        if (!matches.length) {
            productResults.innerHTML = '<div class="product-results-empty">Barang tidak ditemukan.</div>';
            return;
        }

        productResults.innerHTML = matches.map(product => `
            <button type="button" class="product-result" data-product-id="${escapeHtml(product.id)}">
                <span class="product-result-name">${escapeHtml(product.name)}</span>
                <span class="product-result-meta">${product.barcode ? `Barcode: ${escapeHtml(product.barcode)}` : 'Tanpa barcode'}</span>
            </button>
        `).join('');
    }

    function clearProductSelection() {
        productSel.value = '';
        unitSel.innerHTML = '<option value="">Pilih</option>';
        priceInput.value = '0';
        subtotalEl.value = '0';
        subtotalEl.dataset.value = 0;
        div.querySelector('.source-section').classList.add('d-none');
        div.querySelector('.source-content').innerHTML = '';
        div.querySelector('.source-toggle').classList.add('d-none');
        updateTotal();
        updateItemCounter();
    }

    function chooseProduct(product) {
        if (!product) return;
        productSel.value = product.id;
        productSearch.value = product.name;
        productSearch.setCustomValidity('');
        closeProductResults();
        productSel.dispatchEvent(new Event('change', { bubbles: true }));
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

        productSearch.setCustomValidity(product ? '' : 'Pilih barang dari daftar pencarian.');

        if (product) {
            chooseProduct(product);
        } else {
            clearProductSelection();
        }
    }

    productSearch.addEventListener('input', function() {
        const query = this.value.trim().toLowerCase();
        const product = products.find(p =>
            String(p.name).trim().toLowerCase() === query ||
            String(p.barcode || '').trim().toLowerCase() === query
        );

        if (product) {
            chooseProduct(product);
            return;
        }

        clearProductSelection();
        productSearch.setCustomValidity('Pilih barang dari daftar pencarian.');
        renderProductResults();
    });
    productSearch.addEventListener('focus', renderProductResults);
    productSearch.addEventListener('blur', () => setTimeout(closeProductResults, 120));
    productSearch.addEventListener('change', selectProductFromSearch);
    productSearch.addEventListener('keydown', function(event) {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        const firstResult = productResults.querySelector('[data-product-id]');
        if (!firstResult || productResults.classList.contains('d-none')) {
            if (productSel.value) { qtyInput.focus(); qtyInput.select(); }
            return;
        }
        chooseProduct(products.find(p => p.id == firstResult.dataset.productId));
        qtyInput.focus();
        qtyInput.select();
    });

    productResults.addEventListener('mousedown', event => event.preventDefault());
    productResults.addEventListener('click', function(event) {
        const button = event.target.closest('[data-product-id]');
        if (!button) return;
        chooseProduct(products.find(p => p.id == button.dataset.productId));
    });

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

    div.querySelector('.source-toggle').addEventListener('click', function() {
        const section = div.querySelector('.source-section');
        const collapsed = section.classList.toggle('is-collapsed');
        this.classList.toggle('is-expanded', !collapsed);
        this.setAttribute('aria-expanded', String(!collapsed));
        this.title = collapsed ? 'Tampilkan sumber stok' : 'Sembunyikan sumber stok';
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

document.getElementById('addItem').addEventListener('click', () => {
    document.getElementById('cartSearch').value = '';
    const blank = [...document.querySelectorAll('.item-block')].find(row => !row.querySelector('.product-select').value);
    const row = blank || addItem();
    filterCart();
    row.scrollIntoView({ block: 'nearest' });
    row.querySelector('.product-search').focus();
});

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

    qtyInput.value = item.quantity ?? 1;
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
    updateSourceSummary(div);
    updateSaveState();
}

const oldItemList = normalizeOldList(oldItems);
if (oldItemList.length) {
    oldItemList.forEach(item => restoreItemFromOld(addItem(false), item));
} else {
    updateItemCounter();
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
    if (!button) return;
    if (!activeCatalogRow) {
        addProductToCart(products.find(p => p.id == button.dataset.productId));
        return;
    }
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

    const product = products.find(p => String(p.barcode || '').toLowerCase() === code || String(p.name || '').toLowerCase() === code);
    if (!product) {
        const result = document.querySelector('#entryResults .is-active') || document.querySelector('#entryResults [data-product-id]');
        if (result) {
            addProductToCart(products.find(p => p.id == result.dataset.productId));
            return;
        }
        status.className = 'small scanner-status mt-1 text-danger';
        status.textContent = `Barang "${scanner.value.trim()}" tidak ditemukan.`;
        scanner.select();
        return;
    }

    addProductToCart(product);
}

function addProductToCart(product) {
    if (!product) return;
    document.getElementById('cartSearch').value = '';
    const defaultUnit = product.product_units.find(unit => unit.is_default_sale) || product.product_units[0];
    const rows = [...document.querySelectorAll('#itemsContainer .item-block')];
    // Merge only the default unit, price and single-location allocation.
    let target = rows.find(row =>
        row.querySelector('.product-select').value == product.id &&
        row.querySelector('.product-unit-select').value == defaultUnit?.id &&
        parseCurrency(row.querySelector('.price-input').value) === Number(defaultUnit?.sell_price ?? product.sell_price_wholesale) &&
        row.querySelector('.source-location-select')?.value == document.getElementById('defaultSourceLocation').value
    );
    const merged = Boolean(target);
    if (target) {
        const qty = target.querySelector('.qty-input');
        qty.value = Number(qty.value || 0) + 1;
        qty.dispatchEvent(new Event('input', { bubbles: true }));
    } else {
        target = rows.find(row => !row.querySelector('.product-select').value) || addItem(false);
        const select = target.querySelector('.product-select');
        select.value = product.id;
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }
    filterCart();
    target.scrollIntoView({ block: 'nearest' });
    const status = document.getElementById('scannerStatus');
    const stockError = target.classList.contains('has-stock-error');
    status.className = `small scanner-status mt-1 ${stockError ? 'text-danger' : 'text-success'}`;
    status.textContent = stockError ? `${product.name} · Stok perlu diperiksa` : `${product.name} · Qty ${target.querySelector('.qty-input').value}${merged ? ' (ditambah)' : ''}`;
    document.getElementById('barcodeScanner').value = '';
    closeEntryResults();
    if (!document.getElementById('productCatalogModal').classList.contains('show')) {
        document.getElementById('barcodeScanner').focus({ preventScroll: true });
    }
}

function closeEntryResults() {
    const results = document.getElementById('entryResults');
    results.classList.add('d-none');
    results.innerHTML = '';
    document.getElementById('barcodeScanner').setAttribute('aria-expanded', 'false');
}

document.getElementById('barcodeScanner').addEventListener('input', function() {
    const query = this.value.trim().toLowerCase();
    if (!query) { closeEntryResults(); return; }
    const matches = products.filter(p => `${p.name} ${p.barcode || ''}`.toLowerCase().includes(query)).slice(0, 12);
    const results = document.getElementById('entryResults');
    results.innerHTML = matches.map((product, index) => {
        const unit = product.product_units.find(u => u.is_default_sale) || product.product_units[0];
        return `<button type="button" class="product-result ${index === 0 ? 'is-active' : ''}" data-product-id="${escapeHtml(product.id)}"><span class="product-result-name">${escapeHtml(product.name)}</span><span class="product-result-meta">${escapeHtml(product.barcode || '-')} · ${escapeHtml(unit?.unit_name || '')} · Rp ${formatNumber(unit?.sell_price ?? product.sell_price_wholesale)}</span></button>`;
    }).join('') || '<div class="product-results-empty">Barang tidak ditemukan</div>';
    results.classList.remove('d-none');
    this.setAttribute('aria-expanded', 'true');
});
document.getElementById('entryResults').addEventListener('mousedown', event => event.preventDefault());
document.getElementById('entryResults').addEventListener('click', event => {
    const button = event.target.closest('[data-product-id]');
    if (button) addProductToCart(products.find(p => p.id == button.dataset.productId));
});
document.getElementById('barcodeScanner').addEventListener('blur', () => setTimeout(closeEntryResults, 150));
document.getElementById('openCatalog').addEventListener('click', () => {
    activeCatalogRow = null;
    activeCatalogCategory = 'all';
    document.getElementById('catalogSearch').value = '';
    renderCatalogCategories();
    renderCatalogProducts();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('productCatalogModal')).show();
});
document.getElementById('productCatalogModal').addEventListener('shown.bs.modal', () => document.getElementById('catalogSearch').focus());

document.getElementById('barcodeSearch').addEventListener('click', addProductByBarcode);
document.getElementById('barcodeScanner').addEventListener('keydown', function (event) {
    if (event.key === 'Enter') { event.preventDefault(); addProductByBarcode(); }
    if (event.key === 'Escape') closeEntryResults();
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        const buttons = [...document.querySelectorAll('#entryResults [data-product-id]')];
        if (!buttons.length) return;
        event.preventDefault();
        let index = buttons.findIndex(button => button.classList.contains('is-active'));
        index = (index + (event.key === 'ArrowDown' ? 1 : -1) + buttons.length) % buttons.length;
        buttons.forEach((button, i) => button.classList.toggle('is-active', i === index));
        buttons[index].scrollIntoView({ block: 'nearest' });
    }
});

document.getElementById('saleForm').addEventListener('keydown', event => {
    if (event.key !== 'Enter' || !event.target.matches('.qty-input, .price-input, #cartSearch')) return;
    event.preventDefault();
    document.getElementById('barcodeScanner').focus();
});
window.addEventListener('resize', () => {
    document.querySelectorAll('.product-results').forEach(results => results.classList.add('d-none'));
});
</script>
<script>
window.saleDraftConfig = {
    userId: @json(auth()->id()), saleId: @json($editing ? $sale->id : null),
    url: @json(route('sale.drafts.index')), hasOldInput: @json(session()->hasOldInput('items')),
    oldId: @json(old('draft_id')), oldVersion: @json((int) old('draft_version', 0)), startFresh: @json(request()->boolean('new')), preferredDraftId: @json(request('draft'))
};
</script>
<script src="{{ asset('js/sale-drafts.js') }}?v=5"></script>
@endpush
@endsection
