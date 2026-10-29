@extends('layouts.app')
@section('title', 'Transfer Stok Baru')
@section('page-title', 'Transfer Stok Baru')
@push('styles')
<style>
.transfer-product-wrap { position:relative; }
.transfer-product-results { position:absolute; top:100%; left:0; right:0; z-index:1055; max-height:260px; overflow-y:auto; margin-top:.25rem; border:1px solid #d7deea; border-radius:8px; background:#fff; box-shadow:0 10px 26px rgba(15,23,42,.16); }
.transfer-product-result { width:100%; border:0; border-bottom:1px solid #eef1f6; background:#fff; padding:.65rem .75rem; text-align:left; }
.transfer-product-result:last-child { border-bottom:0; }
.transfer-product-result:hover, .transfer-product-result:focus { background:#fff8df; outline:0; }
.transfer-product-name { display:block; color:#172039; font-weight:700; }
.transfer-product-meta { display:block; color:#6b7280; font-size:.78rem; margin-top:.15rem; }
.transfer-product-empty { padding:.7rem .75rem; color:#6b7280; font-size:.85rem; }
.transfer-catalog-tabs { flex-wrap:nowrap; overflow-x:auto; padding-bottom:.25rem; scrollbar-width:thin; }
.transfer-catalog-tabs .nav-link { white-space:nowrap; color:#566078; border:1px solid #e2e6ed; background:#fff; }
.transfer-catalog-tabs .nav-link.active { color:#fff; background:var(--app-accent-2); border-color:var(--app-accent-2); }
.transfer-catalog-list { max-height:55vh; overflow-y:auto; overflow-x:hidden; }
.transfer-catalog-product { height:100%; border:1px solid #e2e6ed; border-radius:8px; background:#fff; padding:.85rem; text-align:left; transition:.15s ease; }
.transfer-catalog-product:hover { border-color:#0d6efd; box-shadow:0 4px 14px rgba(13,110,253,.12); transform:translateY(-1px); }
.transfer-catalog-product .product-name { color:#172039; font-weight:700; }
</style>
@endpush


@section('content')
<form method="POST" id="transferForm" action="{{ route('inventory.transfers.store') }}" class="mx-auto" style="max-width:1200px">
    @csrf

    <section class="bg-white border-top p-3 mb-3" aria-labelledby="transferInfoHeading">
        <h6 id="transferInfoHeading" class="fw-bold mb-3"><i class="bi bi-arrow-left-right me-2"></i>Informasi Transfer</h6>
        <fieldset id="transferInfoFields">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="fromLocation" class="form-label fw-semibold">Lokasi Asal <span class="text-danger">*</span></label>
                    <select name="from_location_id" id="fromLocation" class="form-select" required>
                        <option value="">Pilih lokasi asal</option>
                        @foreach($locations as $loc)<option value="{{ $loc->id }}" @selected(old('from_location_id', request('from_location_id')) == $loc->id)>{{ $loc->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="toLocation" class="form-label fw-semibold">Lokasi Tujuan <span class="text-danger">*</span></label>
                    <select name="to_location_id" id="toLocation" class="form-select" required>
                        <option value="">Pilih lokasi tujuan</option>
                        @foreach($locations as $loc)<option value="{{ $loc->id }}" @selected(old('to_location_id', request('to_location_id')) == $loc->id)>{{ $loc->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="transferDate" class="form-label fw-semibold">Tanggal Transfer <span class="text-danger">*</span></label>
                    <input id="transferDate" type="date" name="transfer_date" class="form-control" value="{{ old('transfer_date', now('Asia/Jakarta')->toDateString()) }}" required>
                </div>
                <div class="col-12">
                    <label for="transferNotes" class="form-label fw-semibold">Catatan</label>
                    <textarea id="transferNotes" name="notes" class="form-control" rows="2" maxlength="500" placeholder="Alasan atau keterangan transfer">{{ old('notes') }}</textarea>
                </div>
            </div>
        </fieldset>
    </section>

    <section class="bg-white border-top p-3 mb-3" aria-labelledby="transferItemsHeading">
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
            <h6 id="transferItemsHeading" class="fw-bold mb-0">Daftar Barang <span id="transferItemCount" class="badge bg-light text-dark">0</span></h6>
            <div id="transferRoute" class="small fw-semibold text-muted" aria-live="polite"></div>
        </div>
        <div id="transferStockAlert" class="alert d-none py-2" role="status" aria-live="polite"></div>
        <button type="button" id="retryTransferStock" class="btn btn-sm btn-outline-secondary mb-3 d-none"><i class="bi bi-arrow-clockwise me-1"></i>Periksa Ulang Stok</button>
        <fieldset id="transferItemsFields">
            <fieldset id="transferComposer" class="mb-3" disabled>
                <div class="row g-2 align-items-end">
                    <div class="col-md-7">
                        <label for="productSearch" class="form-label fw-semibold">Barang</label>
                        <div class="transfer-product-wrap">
                            <div class="input-group">
                                <button type="button" class="btn btn-outline-secondary" id="openProductCatalog" title="Buka katalog barang" aria-label="Buka katalog barang"><i class="bi bi-search"></i></button>
                                <input type="text" id="productSearch" class="form-control" placeholder="Nama barang atau barcode..." autocomplete="off">
                            </div>
                            <div class="transfer-product-results d-none" id="productResults"></div>
                        </div>
                        <input type="hidden" id="productSelect" value="{{ request('product_id') }}">
                    </div>
                    <div class="col-md-3">
                        <label for="qtyInput" class="form-label fw-semibold">Jumlah / Satuan</label>
                        <div class="input-group">
                            <input type="number" id="qtyInput" class="form-control" value="1" min="0.01" step="0.01">
                            <select id="unitSelect" class="form-select" aria-label="Satuan barang baru"><option value="stored">unit</option></select>
                        </div>
                    </div>
                    <div class="col-md-2"><button type="button" id="addTransferItem" class="btn btn-success w-100"><i class="bi bi-plus-lg me-1"></i>Tambah Barang</button></div>
                </div>
                <div id="stockInfo" class="form-text text-primary fw-semibold"></div>
                <div class="form-text" id="qtyHelp"></div>
            </fieldset>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th style="min-width:220px">Barang</th><th style="min-width:170px">Layak Transfer di Asal</th><th style="min-width:120px;width:150px">Jumlah</th><th style="min-width:130px;width:170px">Satuan</th><th style="width:55px"></th></tr></thead>
                    <tbody id="transferItems"></tbody>
                </table>
            </div>
            <div id="transferItemsEmpty" class="text-center text-muted py-4">Belum ada barang.</div>
        </fieldset>
    </section>
    <div class="d-flex flex-wrap gap-2">
        <button type="submit" id="saveTransfer" class="btn btn-primary" disabled><i class="bi bi-arrow-left-right me-1"></i>Simpan Transfer</button>
        <x-back-link :fallback="route('inventory.transfers.index')" label="Batal" :small="false" />
    </div>
</form>
<div class="modal fade" id="transferProductCatalogModal" tabindex="-1" aria-labelledby="transferProductCatalogTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold" id="transferProductCatalogTitle"><i class="bi bi-grid me-2 text-primary"></i>Pilih Barang</h5>
                    <div class="small text-muted" id="transferProductCatalogInfo">Pilih lokasi asal terlebih dahulu.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="input-group mb-3">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="search" class="form-control" id="catalogSearch" placeholder="Cari nama barang atau barcode..." autocomplete="off">
                </div>
                <div class="nav nav-pills gap-2 transfer-catalog-tabs mb-3" id="catalogCategories"></div>
                <div class="transfer-catalog-list pe-1">
                    <div class="row g-2" id="catalogProducts"></div>
                    <div class="text-center text-muted py-5 d-none" id="catalogEmpty">
                        <i class="bi bi-search fs-2 d-block mb-2"></i>Tidak ada barang dengan stok layak transfer yang sesuai di lokasi asal.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


@push('scripts')
<script>
window.stockTransferConfig = {
    products: {{ Illuminate\Support\Js::from($productsJson) }},
    items: {{ Illuminate\Support\Js::from(old('items', [])) }},
    availabilityUrl: {{ Illuminate\Support\Js::from(route('inventory.transfers.availability')) }}
};
</script>
@include('inventory.transfers.scripts')
@endpush
@endsection
