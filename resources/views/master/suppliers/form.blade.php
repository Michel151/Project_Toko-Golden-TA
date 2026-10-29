@extends('layouts.app')
@section('title', isset($supplier) ? 'Ubah Supplier' : 'Tambah Supplier')
@section('page-title', isset($supplier) ? 'Ubah Supplier' : 'Tambah Supplier')

@section('content')
@include('master._header', ['icon'=>'truck', 'title'=>isset($supplier) ? 'Ubah Supplier' : 'Tambah Supplier', 'description'=>'Lengkapi identitas dan kontak pemasok barang.'])
<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="card table-card">
            <div class="card-body">
                <form method="POST" action="{{ isset($supplier)
                    ? route('master.suppliers.update', $supplier)
                    : route('master.suppliers.store') }}">
                    @csrf
                    @isset($supplier) @method('PUT') @endisset

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nama Supplier <span class="text-danger">*</span></label>
                        <input type="text" name="name"
                               class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name', $supplier->name ?? '') }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">No. Telepon / WhatsApp</label>
                        <input type="text" name="phone" class="form-control"
                               value="{{ old('phone', $supplier->phone ?? '') }}"
                               placeholder="Contoh: 08123456789">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Alamat</label>
                        <textarea name="address" class="form-control" rows="3"
                                  placeholder="Alamat lengkap supplier">{{ old('address', $supplier->address ?? '') }}</textarea>
                    </div>

                    @php
                        $supplierProducts = isset($supplier)
                            ? $supplier->products->keyBy('id')
                            : collect();
                    @endphp
                    <div class="border-top pt-3 mt-4 supplier-products-panel" data-supplier-products-panel>
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                            <div>
                                <label class="form-label fw-semibold mb-0">Barang Supplier</label>
                                <div class="small text-muted">Cari dan centang barang yang tersedia dari supplier ini.</div>
                            </div>
                            <div class="supplier-products-summary">
                                <span class="fw-semibold" data-selected-count>0</span>
                                <span class="text-muted">dari {{ $products->count() }} barang dipilih</span>
                            </div>
                        </div>

                        <div class="supplier-products-toolbar mb-3">
                            <div class="input-group supplier-products-search">
                                <button type="button"
                                        class="btn btn-outline-secondary"
                                        data-open-product-catalog
                                        title="Buka katalog barang"
                                        aria-label="Buka katalog barang">
                                    <i class="bi bi-search"></i>
                                </button>
                                <input type="search"
                                       class="form-control"
                                       placeholder="Cari nama barang atau satuan..."
                                       data-product-search
                                       autocomplete="off">
                            </div>
                            <div class="btn-group supplier-products-filter" role="group" aria-label="Filter barang supplier">
                                <input type="radio" class="btn-check" name="supplier_product_filter" id="supplierProductFilterAll" value="all" checked data-product-filter>
                                <label class="btn btn-outline-secondary" for="supplierProductFilterAll">Semua</label>

                                <input type="radio" class="btn-check" name="supplier_product_filter" id="supplierProductFilterSelected" value="selected" data-product-filter>
                                <label class="btn btn-outline-secondary" for="supplierProductFilterSelected">Terpilih</label>

                                <input type="radio" class="btn-check" name="supplier_product_filter" id="supplierProductFilterUnselected" value="unselected" data-product-filter>
                                <label class="btn btn-outline-secondary" for="supplierProductFilterUnselected">Belum</label>
                            </div>
                            <div class="d-flex gap-2 supplier-products-actions">
                                <button type="button" class="btn btn-outline-primary" data-select-visible>
                                    <i class="bi bi-check2-square me-1"></i>Pilih tampil
                                </button>
                                <button type="button" class="btn btn-outline-secondary" data-clear-visible>
                                    <i class="bi bi-square me-1"></i>Hapus tampil
                                </button>
                            </div>
                        </div>

                        <div class="table-responsive border rounded supplier-products-table-wrap">
                            <table class="table table-sm align-middle mb-0 supplier-products-table">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:52px" class="text-center"></th>
                                        <th>Barang</th>
                                        <th style="width:160px">Satuan Beli</th>
                                        <th style="width:220px">Harga Beli Supplier</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($products as $product)
                                        @php
                                            $oldSelected = old("products.{$product->id}.selected");
                                            $selected = old('products') !== null
                                                ? (bool) $oldSelected
                                                : $supplierProducts->has($product->id);
                                            $price = old(
                                                "products.{$product->id}.buy_price",
                                                optional($supplierProducts->get($product->id))->pivot->buy_price ?? $product->buy_price
                                            );
                                        @endphp
                                        <tr data-product-row
                                            data-product-id="{{ $product->id }}"
                                            data-product-name="{{ $product->name }}"
                                            data-product-unit="{{ $product->buyUnit->name }}"
                                            data-product-barcode="{{ $product->barcode }}"
                                            data-product-category-id="{{ $product->category_id ?? 'uncategorized' }}"
                                            data-product-category-name="{{ $product->category->name ?? 'Tanpa Kategori' }}"
                                            data-product-price="{{ (float) $price }}"
                                            data-search-text="{{ Str::lower($product->name . ' ' . $product->buyUnit->name . ' ' . $product->barcode . ' ' . ($product->category->name ?? '')) }}">
                                            <td class="text-center">
                                                <input type="hidden" name="products[{{ $product->id }}][selected]" value="0">
                                                <input type="checkbox"
                                                       class="form-check-input supplier-product-check"
                                                       name="products[{{ $product->id }}][selected]"
                                                       value="1"
                                                       {{ $selected ? 'checked' : '' }}>
                                            </td>
                                            <td>{{ $product->name }}</td>
                                            <td>{{ $product->buyUnit->name }}</td>
                                            <td>
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text">Rp</span>
                                                    <input type="text"
                                                           name="products[{{ $product->id }}][buy_price]"
                                                           class="form-control supplier-product-price js-decimal-currency"
                                                           value="{{ $price }}"
                                                           inputmode="decimal"
                                                           {{ $selected ? '' : 'disabled' }}>
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-3">Belum ada master barang.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                            <div class="supplier-products-empty d-none" data-empty-state>
                                <i class="bi bi-search d-block mb-2"></i>
                                Tidak ada barang yang cocok.
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i>{{ isset($supplier) ? 'Simpan Perubahan' : 'Simpan' }}
                        </button>
                        <x-back-link :fallback="route('master.suppliers.index')" label="Batal" :small="false" />
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="supplierProductCatalogModal" tabindex="-1" aria-labelledby="supplierProductCatalogTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold" id="supplierProductCatalogTitle"><i class="bi bi-grid me-2 text-primary"></i>Pilih Barang</h5>
                    <div class="small text-muted">Cari berdasarkan nama atau barcode, lalu pilih barang supplier.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div class="input-group supplier-catalog-search">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="search" class="form-control" id="supplierCatalogSearch" placeholder="Cari nama barang atau barcode..." autocomplete="off">
                    </div>
                    <div class="supplier-products-summary">
                        <span class="fw-semibold" data-modal-selected-count>0</span>
                        <span class="text-muted">barang dipilih</span>
                    </div>
                </div>
                <div class="nav nav-pills gap-2 supplier-catalog-tabs mb-3" id="supplierCatalogCategories"></div>
                <div class="supplier-catalog-list pe-1">
                    <div class="row g-2" id="supplierCatalogProducts"></div>
                    <div class="text-center text-muted py-5 d-none" id="supplierCatalogEmpty">
                        <i class="bi bi-search fs-2 d-block mb-2"></i>Barang tidak ditemukan.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.querySelectorAll('[data-supplier-products-panel]').forEach((panel) => {
    const searchInput = panel.querySelector('[data-product-search]');
    const filterInputs = panel.querySelectorAll('[data-product-filter]');
    const rows = Array.from(panel.querySelectorAll('[data-product-row]'));
    const selectedCount = panel.querySelector('[data-selected-count]');
    const emptyState = panel.querySelector('[data-empty-state]');
    const catalogModalEl = document.getElementById('supplierProductCatalogModal');
    const catalogSearch = document.getElementById('supplierCatalogSearch');
    const catalogCategories = document.getElementById('supplierCatalogCategories');
    const catalogProducts = document.getElementById('supplierCatalogProducts');
    const catalogEmpty = document.getElementById('supplierCatalogEmpty');
    const catalogSelectedCount = document.querySelector('[data-modal-selected-count]');
    let activeCatalogCategory = 'all';

    function currentFilter() {
        return panel.querySelector('[data-product-filter]:checked')?.value || 'all';
    }

    function normalizeText(value) {
        return String(value || '').toLowerCase().trim();
    }

    function syncRowState(row) {
        const checkbox = row.querySelector('.supplier-product-check');
        const priceInput = row.querySelector('.supplier-product-price');
        priceInput.disabled = ! checkbox.checked;
        row.classList.toggle('supplier-product-selected', checkbox.checked);
    }

    function updateSummary() {
        const totalSelected = rows.filter((row) => row.querySelector('.supplier-product-check').checked).length;
        selectedCount.textContent = totalSelected;
        if (catalogSelectedCount) {
            catalogSelectedCount.textContent = totalSelected;
        }
    }

    function formatNumber(value) {
        return new Intl.NumberFormat('id-ID').format(Math.round(Number(value || 0)));
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        })[char]);
    }

    function rowProduct(row) {
        const priceInput = row.querySelector('.supplier-product-price');

        return {
            id: row.dataset.productId,
            name: row.dataset.productName || '',
            unit: row.dataset.productUnit || '-',
            barcode: row.dataset.productBarcode || '',
            categoryId: String(row.dataset.productCategoryId || 'uncategorized'),
            categoryName: row.dataset.productCategoryName || 'Tanpa Kategori',
            price: normalizeText(priceInput?.value || row.dataset.productPrice).replace(/[^\d]/g, ''),
            selected: row.querySelector('.supplier-product-check').checked,
        };
    }

    function renderCatalogCategories() {
        if (!catalogCategories) return;

        const categories = [...new Map(rows.map((row) => {
            const product = rowProduct(row);
            return [product.categoryId, product.categoryName];
        })).entries()];

        catalogCategories.innerHTML = [
            `<button type="button" class="nav-link ${activeCatalogCategory === 'all' ? 'active' : ''}" data-category="all">Semua</button>`,
            ...categories.map(([id, name]) =>
                `<button type="button" class="nav-link ${activeCatalogCategory === id ? 'active' : ''}" data-category="${escapeHtml(id)}">${escapeHtml(name)}</button>`
            )
        ].join('');
    }

    function renderCatalogProducts() {
        if (!catalogProducts) return;

        const query = normalizeText(catalogSearch?.value || '');
        const filtered = rows
            .map(rowProduct)
            .filter((product) => {
                const matchesCategory = activeCatalogCategory === 'all' || product.categoryId === activeCatalogCategory;
                const matchesSearch = !query
                    || normalizeText(product.name).includes(query)
                    || normalizeText(product.barcode).includes(query)
                    || normalizeText(product.unit).includes(query);
                return matchesCategory && matchesSearch;
            });

        catalogProducts.innerHTML = filtered.map((product) => `
            <div class="col-md-6 col-lg-4">
                <button type="button" class="supplier-catalog-product w-100 ${product.selected ? 'is-selected' : ''}" data-product-id="${escapeHtml(product.id)}">
                    <div class="d-flex justify-content-between gap-2 mb-2">
                        <span class="product-name">${escapeHtml(product.name)}</span>
                        <span class="badge bg-light text-secondary border">${escapeHtml(product.categoryName)}</span>
                    </div>
                    <div class="small text-muted mb-2">${product.barcode ? `Barcode: ${escapeHtml(product.barcode)}` : 'Tanpa barcode'}</div>
                    <div class="d-flex justify-content-between align-items-end gap-2">
                        <div class="small"><span class="text-muted">Satuan beli</span><br><strong>${escapeHtml(product.unit)}</strong></div>
                        <div class="small text-end"><span class="text-muted">Harga supplier</span><br><strong>Rp ${formatNumber(product.price)}</strong></div>
                    </div>
                    <span class="supplier-catalog-picked badge ${product.selected ? 'bg-primary' : 'bg-light text-secondary border'}">${product.selected ? 'Dipilih' : 'Pilih'}</span>
                </button>
            </div>
        `).join('');

        catalogEmpty?.classList.toggle('d-none', filtered.length > 0);
        updateSummary();
    }

    function applyFilters() {
        const keyword = normalizeText(searchInput.value);
        const filter = currentFilter();
        let visibleCount = 0;

        rows.forEach((row) => {
            const checkbox = row.querySelector('.supplier-product-check');
            const matchesSearch = row.dataset.searchText.includes(keyword);
            const matchesFilter = filter === 'all'
                || (filter === 'selected' && checkbox.checked)
                || (filter === 'unselected' && ! checkbox.checked);
            const visible = matchesSearch && matchesFilter;

            row.classList.toggle('d-none', ! visible);
            if (visible) visibleCount++;
        });

        emptyState?.classList.toggle('d-none', visibleCount > 0);
        updateSummary();
    }

    rows.forEach((row) => {
        const checkbox = row.querySelector('.supplier-product-check');

        syncRowState(row);
        checkbox.addEventListener('change', () => {
            syncRowState(row);
            applyFilters();
            renderCatalogProducts();
        });

        row.querySelector('.supplier-product-price')?.addEventListener('input', renderCatalogProducts);
    });

    searchInput?.addEventListener('input', applyFilters);
    filterInputs.forEach((input) => input.addEventListener('change', applyFilters));

    panel.querySelector('[data-select-visible]')?.addEventListener('click', () => {
        rows.filter((row) => ! row.classList.contains('d-none')).forEach((row) => {
            row.querySelector('.supplier-product-check').checked = true;
            syncRowState(row);
        });
        applyFilters();
        renderCatalogProducts();
    });

    panel.querySelector('[data-clear-visible]')?.addEventListener('click', () => {
        rows.filter((row) => ! row.classList.contains('d-none')).forEach((row) => {
            row.querySelector('.supplier-product-check').checked = false;
            syncRowState(row);
        });
        applyFilters();
        renderCatalogProducts();
    });

    panel.querySelector('[data-open-product-catalog]')?.addEventListener('click', () => {
        activeCatalogCategory = 'all';
        if (catalogSearch) {
            catalogSearch.value = searchInput?.value || '';
        }
        renderCatalogCategories();
        renderCatalogProducts();
        bootstrap.Modal.getOrCreateInstance(catalogModalEl).show();
        catalogModalEl?.addEventListener('shown.bs.modal', () => catalogSearch?.focus(), { once: true });
    });

    catalogSearch?.addEventListener('input', renderCatalogProducts);

    catalogCategories?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-category]');
        if (!button) return;
        activeCatalogCategory = button.dataset.category;
        catalogCategories.querySelectorAll('.nav-link').forEach((tab) => tab.classList.toggle('active', tab === button));
        renderCatalogProducts();
    });

    catalogProducts?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-product-id]');
        if (!button) return;

        const row = rows.find((item) => item.dataset.productId === button.dataset.productId);
        if (!row) return;

        const checkbox = row.querySelector('.supplier-product-check');
        checkbox.checked = !checkbox.checked;
        syncRowState(row);
        applyFilters();
        renderCatalogProducts();
    });

    applyFilters();
});
</script>
@endpush

@push('styles')
<style>
    .supplier-products-summary {
        border: 1px solid #e8ebf1;
        border-radius: 8px;
        padding: .45rem .7rem;
        background: #f8fafc;
        color: #2f394b;
        white-space: nowrap;
    }

    .supplier-products-toolbar {
        display: grid;
        grid-template-columns: minmax(240px, 1fr) auto auto;
        gap: .75rem;
        align-items: center;
    }

    .supplier-products-search,
    .supplier-catalog-search {
        min-width: 0;
    }

    .supplier-products-search > .btn {
        border-color: #dee2e6;
    }

    .supplier-catalog-search {
        flex: 1 1 420px;
    }

    .supplier-products-table-wrap {
        max-height: 440px;
        overflow: auto;
        position: relative;
    }

    .supplier-products-table thead th {
        position: sticky;
        top: 0;
        z-index: 1;
    }

    .supplier-product-selected {
        background: #f7fbff;
    }

    .supplier-products-empty {
        padding: 2rem 1rem;
        text-align: center;
        color: #7b8498;
        border-top: 1px solid #edf0f4;
    }

    .supplier-catalog-tabs {
        flex-wrap: nowrap;
        overflow-x: auto;
        padding-bottom: .25rem;
        scrollbar-width: thin;
    }

    .supplier-catalog-tabs .nav-link {
        border: 1px solid #e2e6ed;
        background: #fff;
        color: #566078;
        white-space: nowrap;
    }

    .supplier-catalog-tabs .nav-link.active {
        background: var(--app-accent-2);
        border-color: var(--app-accent-2);
        color: #fff;
    }

    .supplier-catalog-list {
        max-height: 55vh;
        overflow-y: auto;
        overflow-x: hidden;
    }

    .supplier-catalog-product {
        position: relative;
        height: 100%;
        min-height: 154px;
        border: 1px solid #e2e6ed;
        border-radius: 11px;
        background: #fff;
        padding: .85rem;
        padding-bottom: 2.4rem;
        text-align: left;
        transition: .15s ease;
    }

    .supplier-catalog-product:hover,
    .supplier-catalog-product.is-selected {
        border-color: #0d6efd;
        box-shadow: 0 4px 14px rgba(13, 110, 253, .12);
    }

    .supplier-catalog-product .product-name {
        color: #172039;
        font-weight: 700;
    }

    .supplier-catalog-picked {
        position: absolute;
        right: .85rem;
        bottom: .85rem;
    }

    @media (max-width: 992px) {
        .supplier-products-toolbar {
            grid-template-columns: 1fr;
        }

        .supplier-products-filter,
        .supplier-products-actions {
            width: 100%;
        }

        .supplier-products-filter > .btn,
        .supplier-products-actions > .btn {
            flex: 1 1 0;
        }
    }
</style>
@endpush
@endsection
