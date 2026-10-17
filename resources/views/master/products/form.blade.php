@extends('layouts.app')
@section('title', isset($product) ? 'Edit Barang' : 'Tambah Barang')
@section('page-title', isset($product) ? 'Edit Barang' : 'Tambah Barang')

@section('content')
@include('master._header', ['icon'=>'box-seam', 'title'=>isset($product) ? 'Edit Barang' : 'Tambah Barang', 'description'=>'Lengkapi identitas, barcode, satuan, harga, dan batas minimum stok.'])
<form method="POST" action="{{ isset($product)
    ? route('master.products.update', $product)
    : route('master.products.store') }}">
    @csrf
    @isset($product) @method('PUT') @endisset

    <div class="row g-3">

        {{-- Informasi Dasar --}}
        <div class="col-md-8">
            <div class="card table-card">
                <div class="card-header bg-white border-0 pt-3">
                    <h6 class="fw-bold mb-0"><i class="bi bi-info-circle me-2"></i>Informasi Dasar</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Nama Barang <span class="text-danger">*</span></label>
                            <input type="text" name="name"
                                   class="form-control @error('name') is-invalid @enderror"
                                   value="{{ old('name', $product->name ?? '') }}"
                                   placeholder="Nama barang" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Kode Barcode / SKU</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                                <input type="text" name="barcode"
                                       class="form-control font-monospace @error('barcode') is-invalid @enderror"
                                       value="{{ old('barcode', $product->barcode ?? '') }}"
                                       placeholder="Kosongkan untuk dibuat otomatis">
                                @error('barcode')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="form-text">Bisa diisi dari barcode kemasan atau dikosongkan agar sistem membuat kode unik.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Kategori <span class="text-danger">*</span></label>
                            <select name="category_id" class="form-select @error('category_id') is-invalid @enderror" required>
                                <option value="">-- Pilih Kategori --</option>
                                @foreach($categories as $cat)
                                <option value="{{ $cat->id }}" {{ old('category_id', $product->category_id ?? '') == $cat->id ? 'selected' : '' }}>
                                    {{ $cat->name }}
                                </option>
                                @endforeach
                            </select>
                            @error('category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input type="hidden" name="is_expirable" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" name="is_expirable" value="1"
                                       id="isExpirable" @checked(old('is_expirable', $product->is_expirable ?? false))>
                                <label class="form-check-label fw-semibold" for="isExpirable">Memiliki Kedaluwarsa</label>
                            </div>
                            <div class="form-text">Aktifkan untuk barang yang tanggal kedaluwarsanya harus dicatat saat penerimaan.</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input type="hidden" name="is_active" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" name="is_active" value="1"
                                       id="isActive" @checked(old('is_active', $product->is_active ?? true))>
                                <label class="form-check-label fw-semibold" for="isActive">Barang Aktif</label>
                            </div>
                            <div class="form-text">Barang nonaktif tetap tersimpan, tetapi tidak dipilih untuk transaksi baru.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @php
            $thresholdProductUnits = old('product_units');
            if (!$thresholdProductUnits) {
                if (isset($product) && $product->productUnits->isNotEmpty()) {
                    $thresholdProductUnits = $product->productUnits
                        ->sortByDesc(fn ($productUnit) => (float) $productUnit->conversion_to_base)
                        ->values()
                        ->map(fn ($productUnit) => [
                            'unit_id' => $productUnit->unit_id,
                            'conversion_to_base' => (float) $productUnit->conversion_to_base,
                            'is_base_unit' => $productUnit->is_base_unit,
                        ])
                        ->all();
                } else {
                    $thresholdProductUnits = [[
                        'unit_id' => $product->buy_unit_id ?? '',
                        'conversion_to_base' => 1,
                        'is_base_unit' => true,
                    ]];
                }
            }

            $thresholdBaseIndex = old('base_unit_index');
            foreach ($thresholdProductUnits as $i => $unitRow) {
                if ($thresholdBaseIndex === null && !empty($unitRow['is_base_unit'])) $thresholdBaseIndex = $i;
            }
            $thresholdBaseIndex = $thresholdBaseIndex ?? count($thresholdProductUnits) - 1;

            $thresholdUnitChoice = function ($baseQty) use ($thresholdProductUnits, $thresholdBaseIndex) {
                $qty = (float) $baseQty;
                if ($qty <= 0) return [$thresholdBaseIndex, 0];

                foreach ($thresholdProductUnits as $i => $unitRow) {
                    $conversion = (float) ($unitRow['conversion_to_base'] ?? 1);
                    if ($conversion > 0 && abs(fmod($qty, $conversion)) < 0.0001) {
                        return [$i, $qty / $conversion];
                    }
                }

                return [$thresholdBaseIndex, $qty];
            };

            [$minTotalUnitIndex, $minTotalDisplayQty] = old('min_stock_total') !== null
                ? [old('min_stock_total_unit_index', $thresholdBaseIndex), old('min_stock_total')]
                : $thresholdUnitChoice($product->min_stock_total ?? 0);

            $locationThresholds = $productLocations ?? collect();
            $locationThresholdChoice = function ($locationId) use ($thresholdUnitChoice, $locationThresholds, $thresholdBaseIndex) {
                if (old("locations.{$locationId}") !== null) {
                    return [
                        old("location_units.{$locationId}", $thresholdBaseIndex),
                        old("locations.{$locationId}"),
                    ];
                }

                return $thresholdUnitChoice($locationThresholds[$locationId]->min_stock ?? 0);
            };
            $activeLocationIds = collect(old('active_locations', isset($product) ? $locationThresholds->keys()->all() : []))
                ->map(fn ($locationId) => (int) $locationId)
                ->all();
        @endphp

        {{-- Min Stok Total --}}
        <div class="col-md-4">
            <div class="card table-card">
                <div class="card-header bg-white border-0 pt-3">
                    <h6 class="fw-bold mb-0"><i class="bi bi-exclamation-triangle me-2 text-warning"></i>Stok Minimum</h6>
                </div>
                <div class="card-body">
                    <label class="form-label fw-semibold">Min. Stok Total (semua lokasi)</label>
                    <div class="input-group">
                        <input type="number" name="min_stock_total" class="form-control"
                               value="{{ $minTotalDisplayQty }}"
                               min="0" step="0.01">
                        <select name="min_stock_total_unit_index" class="form-select threshold-unit-select" style="max-width:130px">
                            @foreach($thresholdProductUnits as $i => $unitRow)
                            <option value="{{ $i }}" @selected((string) $minTotalUnitIndex === (string) $i)>
                                {{ $units->firstWhere('id', $unitRow['unit_id'] ?? null)?->name ?? 'Satuan' }}
                            </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-text">Jika total stok ≤ nilai ini, muncul notifikasi order ke supplier.</div>
                </div>
            </div>
        </div>

        @php
            $existingProductUnits = old('product_units');
            if (!$existingProductUnits) {
                if (isset($product) && $product->productUnits->isNotEmpty()) {
                    $existingProductUnits = $product->productUnits
                        ->sortByDesc(fn ($productUnit) => (float) $productUnit->conversion_to_base)
                        ->values()
                        ->map(fn ($productUnit) => [
                            'id' => $productUnit->id,
                            'unit_id' => $productUnit->unit_id,
                            'contains_quantity' => (float) ($productUnit->contains_quantity ?? $productUnit->conversion_to_base),
                            'contains_unit_id' => $productUnit->contains_unit_id,
                            'conversion_to_base' => (float) $productUnit->conversion_to_base,
                            'buy_price' => (float) $productUnit->buy_price,
                            'sell_price' => (float) $productUnit->sell_price,
                            'is_base_unit' => $productUnit->is_base_unit,
                            'is_default_purchase' => $productUnit->is_default_purchase,
                            'is_default_sale' => $productUnit->is_default_sale,
                        ])
                        ->all();
                } elseif (isset($product)) {
                    $existingProductUnits = [[
                        'unit_id' => $product->buy_unit_id,
                        'contains_quantity' => 1,
                        'contains_unit_index' => '',
                        'conversion_to_base' => 1,
                        'buy_price' => (float) $product->buy_price,
                        'sell_price' => (float) $product->sell_price_wholesale,
                        'is_base_unit' => true,
                        'is_default_purchase' => true,
                        'is_default_sale' => true,
                    ]];
                } else {
                    $existingProductUnits = [[
                        'unit_id' => '',
                        'contains_quantity' => 1,
                        'contains_unit_index' => '',
                        'conversion_to_base' => 1,
                        'buy_price' => 0,
                        'sell_price' => 0,
                        'is_base_unit' => true,
                        'is_default_purchase' => true,
                        'is_default_sale' => true,
                    ]];
                }
            }
            $unitIdToRowIndex = [];
            foreach ($existingProductUnits as $i => $unitRow) {
                if (!empty($unitRow['id'])) $unitIdToRowIndex[$unitRow['id']] = $i;
            }
            foreach ($existingProductUnits as $i => $unitRow) {
                if (!array_key_exists('contains_unit_index', $existingProductUnits[$i])) {
                    $existingProductUnits[$i]['contains_unit_index'] = !empty($unitRow['contains_unit_id']) && array_key_exists($unitRow['contains_unit_id'], $unitIdToRowIndex)
                        ? $unitIdToRowIndex[$unitRow['contains_unit_id']]
                        : '';
                }
            }
            $baseIndex = old('base_unit_index');
            $purchaseIndex = old('default_purchase_unit_index');
            $saleIndex = old('default_sale_unit_index');
            foreach ($existingProductUnits as $i => $unitRow) {
                if ($baseIndex === null && !empty($unitRow['is_base_unit'])) $baseIndex = $i;
                if ($purchaseIndex === null && !empty($unitRow['is_default_purchase'])) $purchaseIndex = $i;
                if ($saleIndex === null && !empty($unitRow['is_default_sale'])) $saleIndex = $i;
            }
            $baseIndex = $baseIndex ?? count($existingProductUnits) - 1;
            $purchaseIndex = $purchaseIndex ?? 0;
            $saleIndex = $saleIndex ?? $baseIndex;
        @endphp

        {{-- Satuan & Harga --}}
        <div class="col-12">
            <div class="card table-card">
                <div class="card-header bg-white border-0 pt-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="fw-bold mb-0"><i class="bi bi-currency-dollar me-2"></i>Multi Satuan & Harga</h6>
                            <div class="small text-muted mt-1">Stok dihitung dalam satuan dasar, tetapi ditampilkan bertingkat.</div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addProductUnit">
                            <i class="bi bi-plus-circle me-1"></i>Tambah Satuan
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    @error('product_units')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
                    <input type="hidden" name="base_unit_index" id="baseUnitIndex" value="{{ $baseIndex }}">
                    <input type="hidden" name="default_purchase_unit_index" id="purchaseUnitIndex" value="{{ $purchaseIndex }}">
                    <input type="hidden" name="default_sale_unit_index" id="saleUnitIndex" value="{{ $saleIndex }}">
                    <div class="table-responsive">
                        <table class="table align-middle mb-2" id="productUnitsTable">
                            <thead>
                                <tr>
                                    <th style="min-width:190px">Satuan</th>
                                    <th style="min-width:120px">Berisi</th>
                                    <th style="min-width:190px">Satuan Isi</th>
                                    <th style="min-width:170px">Total ke Dasar</th>
                                    <th style="min-width:150px">Harga Beli</th>
                                    <th style="min-width:150px">Harga Jual</th>
                                    <th class="text-center">Dasar</th>
                                    <th class="text-center">Beli</th>
                                    <th class="text-center">Jual</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($existingProductUnits as $i => $unitRow)
                                <tr class="product-unit-row">
                                    <td>
                                        <select name="product_units[{{ $i }}][unit_id]" class="form-select unit-select" required>
                                            <option value="">-- Pilih --</option>
                                            @foreach($units as $unit)
                                            <option value="{{ $unit->id }}" @selected(($unitRow['unit_id'] ?? '') == $unit->id)>{{ $unit->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" name="product_units[{{ $i }}][contains_quantity]" class="form-control contains-qty-input"
                                               value="{{ $unitRow['contains_quantity'] ?? 1 }}" min="0.0001" step="0.0001" required>
                                        <input type="hidden" name="product_units[{{ $i }}][conversion_to_base]" class="conversion-input"
                                               value="{{ $unitRow['conversion_to_base'] ?? 1 }}">
                                    </td>
                                    <td>
                                        <select name="product_units[{{ $i }}][contains_unit_index]" class="form-select contains-unit-select">
                                            <option value="">Dasar</option>
                                            @foreach($existingProductUnits as $j => $targetRow)
                                                @if($j !== $i)
                                                <option value="{{ $j }}" @selected((string)($unitRow['contains_unit_index'] ?? '') === (string)$j)>
                                                    {{ $units->firstWhere('id', $targetRow['unit_id'] ?? null)?->name ?? 'Satuan '.$j }}
                                                </option>
                                                @endif
                                            @endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" class="form-control conversion-preview" value="{{ $unitRow['conversion_to_base'] ?? 1 }}" readonly>
                                    </td>
                                    <td>
                                        <div class="input-group">
                                            <span class="input-group-text">Rp</span>
                                            <input type="text" name="product_units[{{ $i }}][buy_price]" class="form-control buy-price-input"
                                                   value="{{ number_format((float) ($unitRow['buy_price'] ?? 0), 2, ',', '.') }}" inputmode="decimal">
                                        </div>
                                    </td>
                                    <td>
                                        <div class="input-group">
                                            <span class="input-group-text">Rp</span>
                                            <input type="text" name="product_units[{{ $i }}][sell_price]" class="form-control js-currency-input"
                                                   value="{{ number_format((float) ($unitRow['sell_price'] ?? 0), 0, ',', '.') }}" inputmode="numeric" required>
                                        </div>
                                    </td>
                                    <td class="text-center"><input type="radio" class="form-check-input base-radio" name="base_unit_radio" value="{{ $i }}" @checked((string) $baseIndex === (string) $i)></td>
                                    <td class="text-center"><input type="radio" class="form-check-input purchase-radio" name="purchase_unit_radio" value="{{ $i }}" @checked((string) $purchaseIndex === (string) $i)></td>
                                    <td class="text-center"><input type="radio" class="form-check-input sale-radio" name="sale_unit_radio" value="{{ $i }}" @checked((string) $saleIndex === (string) $i)></td>
                                    <td><button type="button" class="btn btn-sm btn-outline-danger remove-product-unit"><i class="bi bi-trash"></i></button></td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="form-text">Contoh rokok: Dus berisi 6 Bal, Bal berisi 10 Slop, Slop berisi 10 Bungkus, Bungkus pilih Dasar.</div>
                    <div class="small fw-semibold mt-2" id="unitPreview"></div>
                </div>
            </div>
        </div>
        </div>

        {{-- Min Stok per Lokasi --}}
        <div class="col-12">
            <div class="card table-card">
                <div class="card-header bg-white border-0 pt-3">
                    <h6 class="fw-bold mb-0"><i class="bi bi-geo-alt me-2"></i>Stok Minimum per Lokasi</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        @foreach($locations as $location)
                        @php
                            [$locationUnitIndex, $locationDisplayQty] = $locationThresholdChoice($location->id);
                            $locationActive = in_array((int) $location->id, $activeLocationIds, true);
                        @endphp
                        <div class="col-md-4">
                            <div class="form-check mb-2">
                                <input class="form-check-input location-active-check" type="checkbox"
                                       name="active_locations[]" value="{{ $location->id }}"
                                       id="active_location_{{ $location->id }}" @checked($locationActive)>
                                <label class="form-check-label fw-semibold" for="active_location_{{ $location->id }}">
                                    {{ $location->name }}
                                </label>
                            </div>
                            <div class="input-group">
                                <input type="number" name="locations[{{ $location->id }}]" class="form-control location-threshold-input"
                                       value="{{ $locationDisplayQty }}"
                                       min="0" step="0.01" placeholder="0" @disabled(!$locationActive)>
                                <select name="location_units[{{ $location->id }}]" class="form-select threshold-unit-select location-threshold-unit" style="max-width:130px" @disabled(!$locationActive)>
                                    @foreach($thresholdProductUnits as $i => $unitRow)
                                    <option value="{{ $i }}" @selected((string) $locationUnitIndex === (string) $i)>
                                        {{ $units->firstWhere('id', $unitRow['unit_id'] ?? null)?->name ?? 'Satuan' }}
                                    </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-text">Jika stok ≤ ini, notifikasi transfer muncul.</div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i>{{ isset($product) ? 'Perbarui Barang' : 'Simpan Barang' }}
                </button>
                <a href="{{ route('master.products.index') }}" class="btn btn-outline-secondary">Batal</a>
            </div>
        </div>
    </div>
</form>

@push('scripts')
<script>
const unitOptionsHtml = `@foreach($units as $unit)<option value="{{ $unit->id }}">{{ $unit->name }}</option>@endforeach`;
let productUnitIndex = {{ count($existingProductUnits) }};

function refreshProductUnitRows() {
    const rows = [...document.querySelectorAll('.product-unit-row')];
    const newIndexes = new Map(rows.map((row, index) => [row.querySelector('.base-radio').value, index]));
    const childIndexes = rows.map(row => {
        const oldIndex = row.querySelector('.contains-unit-select').value;
        return oldIndex === '' ? '' : (newIndexes.get(oldIndex) ?? '');
    });
    rows.forEach((row, index) => {
        row.querySelectorAll('[name]').forEach(input => {
            input.name = input.name.replace(/product_units\[\d+\]/, `product_units[${index}]`);
        });
        row.querySelector('.base-radio').value = index;
        row.querySelector('.purchase-radio').value = index;
        row.querySelector('.sale-radio').value = index;
    });

    rows.forEach((row, index) => {
        const currentValue = childIndexes[index];
        row.querySelector('.contains-unit-select').innerHTML = '<option value="">Dasar</option>' + rows
            .map((targetRow, targetIndex) => {
                if (targetIndex === index) return '';
                const select = targetRow.querySelector('.unit-select');
                const label = select.options[select.selectedIndex]?.text || `Satuan ${targetIndex + 1}`;
                const selected = String(currentValue) === String(targetIndex) ? 'selected' : '';
                return `<option value="${targetIndex}" ${selected}>${label}</option>`;
            })
            .join('');
    });

    const checkedBase = document.querySelector('.base-radio:checked') || document.querySelector('.base-radio');
    const checkedPurchase = document.querySelector('.purchase-radio:checked') || document.querySelector('.purchase-radio');
    const checkedSale = document.querySelector('.sale-radio:checked') || document.querySelector('.sale-radio');
    if (checkedBase) checkedBase.checked = true;
    if (checkedPurchase) checkedPurchase.checked = true;
    if (checkedSale) checkedSale.checked = true;

    document.getElementById('baseUnitIndex').value = checkedBase ? checkedBase.value : 0;
    document.getElementById('purchaseUnitIndex').value = checkedPurchase ? checkedPurchase.value : 0;
    document.getElementById('saleUnitIndex').value = checkedSale ? checkedSale.value : 0;
    refreshThresholdUnitSelects(rows);
    updateUnitPreview();
}

function refreshThresholdUnitSelects(rows = [...document.querySelectorAll('.product-unit-row')]) {
    document.querySelectorAll('.threshold-unit-select').forEach(select => {
        const currentValue = select.value;
        select.innerHTML = rows.map((row, index) => {
            const unitSelect = row.querySelector('.unit-select');
            const label = unitSelect.options[unitSelect.selectedIndex]?.text || `Satuan ${index + 1}`;
            const selected = String(currentValue) === String(index) ? 'selected' : '';
            return `<option value="${index}" ${selected}>${label}</option>`;
        }).join('');
    });
}

function updateUnitPreview() {
    const domRows = [...document.querySelectorAll('.product-unit-row')];
    const rows = domRows
        .map((row, index) => {
            const select = row.querySelector('.unit-select');
            return {
                index,
                unit: select.options[select.selectedIndex]?.text || '',
                qty: parseFloat(row.querySelector('.contains-qty-input').value) || 0,
                childIndex: row.querySelector('.contains-unit-select').value,
                conversion: 0,
            };
        })
        .filter(row => row.unit);

    const byIndex = new Map(rows.map(row => [row.index, row]));
    const resolving = new Set();
    const baseIndex = Number(document.getElementById('baseUnitIndex').value);

    function resolve(index) {
        const row = byIndex.get(index);
        if (!row) return 0;
        if (resolving.has(index)) return 0;
        if (row.conversion > 0) return row.conversion;
        resolving.add(index);
        row.conversion = index === baseIndex ? 1
            : row.childIndex === '' ? 0 : row.qty * resolve(Number(row.childIndex));
        resolving.delete(index);
        return row.conversion;
    }

    domRows.forEach((row, index) => {
        const conversion = resolve(index);
        row.querySelector('.conversion-input').value = conversion || 1;
        row.querySelector('.conversion-preview').value = conversion
            ? new Intl.NumberFormat('id-ID').format(conversion)
            : 'Rantai tidak valid';
    });

    const sortedRows = rows
        .filter(row => row.unit && row.conversion > 0)
        .sort((a, b) => b.conversion - a.conversion);

    const base = sortedRows[sortedRows.length - 1]?.unit || 'satuan dasar';
    document.getElementById('unitPreview').textContent = rows.length
        ? sortedRows.map(row => `${row.unit} = ${new Intl.NumberFormat('id-ID').format(row.conversion)} ${base}`).join(' | ')
        : '';
    updateBuyPrices(domRows, rows);
}

function parseBuyPrice(value) {
    const cleaned = String(value ?? '').replace(/[^\d,.]/g, '');
    if (cleaned.includes(',')) return Number(cleaned.replace(/\./g, '').replace(',', '.')) || 0;
    if (/^\d+\.\d{1,2}$/.test(cleaned)) return Number(cleaned);
    return Number(cleaned.replace(/\./g, '')) || 0;
}

function formatBuyPrice(value) {
    return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(value);
}

function updateBuyPrices(domRows, rows) {
    const purchaseIndex = Number(document.getElementById('purchaseUnitIndex').value);
    const purchaseRow = domRows[purchaseIndex];
    if (!purchaseRow) return;
    const purchasePrice = parseBuyPrice(purchaseRow.querySelector('.buy-price-input').value);
    const purchaseConversion = rows.find(row => row.index === purchaseIndex)?.conversion || 0;

    domRows.forEach((row, index) => {
        const input = row.querySelector('.buy-price-input');
        input.readOnly = index !== purchaseIndex;
        input.classList.toggle('bg-body-secondary', input.readOnly);
        if (!input.readOnly) return;
        const conversion = rows.find(unit => unit.index === index)?.conversion || 0;
        input.value = purchaseConversion > 0 && conversion > 0
            ? formatBuyPrice(Math.round((purchasePrice * conversion / purchaseConversion + Number.EPSILON) * 100) / 100)
            : '';
    });
}

function addProductUnitRow() {
    const tbody = document.querySelector('#productUnitsTable tbody');
    const row = document.createElement('tr');
    row.className = 'product-unit-row';
    row.innerHTML = `
        <td><select name="product_units[${productUnitIndex}][unit_id]" class="form-select unit-select" required><option value="">-- Pilih --</option>${unitOptionsHtml}</select></td>
        <td><input type="number" name="product_units[${productUnitIndex}][contains_quantity]" class="form-control contains-qty-input" value="1" min="0.0001" step="0.0001" required><input type="hidden" name="product_units[${productUnitIndex}][conversion_to_base]" class="conversion-input" value="1"></td>
        <td><select name="product_units[${productUnitIndex}][contains_unit_index]" class="form-select contains-unit-select"><option value="">Dasar</option></select></td>
        <td><input type="text" class="form-control conversion-preview" value="1" readonly></td>
        <td><div class="input-group"><span class="input-group-text">Rp</span><input type="text" name="product_units[${productUnitIndex}][buy_price]" class="form-control buy-price-input" value="0" inputmode="decimal"></div></td>
        <td><div class="input-group"><span class="input-group-text">Rp</span><input type="text" name="product_units[${productUnitIndex}][sell_price]" class="form-control js-currency-input" value="0" inputmode="numeric" required></div></td>
        <td class="text-center"><input type="radio" class="form-check-input base-radio" name="base_unit_radio" value="${productUnitIndex}"></td>
        <td class="text-center"><input type="radio" class="form-check-input purchase-radio" name="purchase_unit_radio" value="${productUnitIndex}"></td>
        <td class="text-center"><input type="radio" class="form-check-input sale-radio" name="sale_unit_radio" value="${productUnitIndex}"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger remove-product-unit"><i class="bi bi-trash"></i></button></td>
    `;
    tbody.appendChild(row);
    productUnitIndex++;
    refreshProductUnitRows();
}

document.getElementById('addProductUnit').addEventListener('click', addProductUnitRow);
document.getElementById('productUnitsTable').addEventListener('input', function(event) {
    if (event.target.matches('.buy-price-input')) {
        const [whole, decimal] = event.target.value.replace(/[^\d,]/g, '').split(',');
        event.target.value = (whole ? formatBuyPrice(Number(whole)) : '')
            + (decimal !== undefined ? ',' + decimal.slice(0, 2) : '');
    }
    updateUnitPreview();
});
document.getElementById('productUnitsTable').addEventListener('change', function(event) {
    if (event.target.matches('.unit-select')) {
        refreshProductUnitRows();
        return;
    }
    if (event.target.matches('.base-radio')) {
        document.getElementById('baseUnitIndex').value = event.target.value;
        event.target.closest('tr').querySelector('.contains-unit-select').value = '';
    }
    if (event.target.matches('.purchase-radio')) document.getElementById('purchaseUnitIndex').value = event.target.value;
    if (event.target.matches('.sale-radio')) document.getElementById('saleUnitIndex').value = event.target.value;
    updateUnitPreview();
});
document.getElementById('productUnitsTable').addEventListener('click', function(event) {
    const button = event.target.closest('.remove-product-unit');
    if (!button) return;
    if (document.querySelectorAll('.product-unit-row').length <= 1) return;
    button.closest('tr').remove();
    refreshProductUnitRows();
});
document.querySelectorAll('.buy-price-input').forEach(input => {
    input.value = formatBuyPrice(parseBuyPrice(input.value));
});
refreshProductUnitRows();

document.querySelectorAll('.location-active-check').forEach(checkbox => {
    function syncLocationInputs() {
        const wrapper = checkbox.closest('.col-md-4');
        wrapper.querySelectorAll('.location-threshold-input, .location-threshold-unit').forEach(input => {
            input.disabled = !checkbox.checked;
        });
    }

    checkbox.addEventListener('change', syncLocationInputs);
    syncLocationInputs();
});
</script>
@endpush
@endsection
