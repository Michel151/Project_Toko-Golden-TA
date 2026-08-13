@extends('layouts.app')
@section('title', 'Transfer Stok Baru')
@section('page-title', 'Transfer Stok Baru')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card table-card">
            <div class="card-body">
                <form method="POST" action="{{ route('inventory.transfers.store') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Barang <span class="text-danger">*</span></label>
                        <select name="product_id" id="productSelect" class="form-select @error('product_id') is-invalid @enderror" required>
                            <option value="">-- Pilih Barang --</option>
                            @foreach($products as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                            @endforeach
                        </select>
                        @error('product_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Dari Lokasi <span class="text-danger">*</span></label>
                            <select name="from_location_id" id="fromLocation" class="form-select @error('from_location_id') is-invalid @enderror" required>
                                <option value="">-- Pilih Lokasi --</option>
                                @foreach($locations as $loc)
                                <option value="{{ $loc->id }}">{{ $loc->name }}</option>
                                @endforeach
                            </select>
                            @error('from_location_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div id="stockInfo" class="form-text text-primary fw-semibold mt-1"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Ke Lokasi <span class="text-danger">*</span></label>
                            <select name="to_location_id" class="form-select @error('to_location_id') is-invalid @enderror" required>
                                <option value="">-- Pilih Lokasi --</option>
                                @foreach($locations as $loc)
                                <option value="{{ $loc->id }}">{{ $loc->name }}</option>
                                @endforeach
                            </select>
                            @error('to_location_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Jumlah <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" name="quantity" id="qtyInput"
                                       class="form-control @error('quantity') is-invalid @enderror"
                                       value="{{ old('quantity') }}" min="0.01" step="0.01" required>
                                <select name="product_unit_id" id="unitSelect" class="form-select" style="max-width:140px" required>
                                    <option value="stored">unit</option>
                                </select>
                            </div>
                            <div class="form-text" id="qtyHelp"></div>
                            @error('quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tanggal Transfer <span class="text-danger">*</span></label>
                            <input type="date" name="transfer_date" class="form-control" value="{{ date('Y-m-d') }}" required>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Catatan</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Alasan transfer, keterangan, dll">{{ old('notes') }}</textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-arrow-left-right me-1"></i>Simpan Transfer
                        </button>
                        <a href="{{ route('inventory.transfers.index') }}" class="btn btn-outline-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
const products = {!! json_encode($productsJson) !!};

function formatStok(rawQty, product) {
    if (product.product_units && product.product_units.length) {
        const units = [...product.product_units].sort((a, b) => b.conversion_to_base - a.conversion_to_base);
        let remaining = rawQty;
        const parts = [];

        units.forEach(unit => {
            const conversion = Number(unit.conversion_to_base || 0);
            if (conversion <= 0) return;

            const amount = Math.floor(remaining / conversion);
            if (amount > 0) {
                parts.push(`${amount.toLocaleString('id-ID')} ${unit.name}`);
                remaining = remaining % conversion;
            }
        });

        return parts.length ? parts.join(' ') : `0 ${units[units.length - 1].name}`;
    }

    if (product.can_retail && product.conversion_factor > 0) {
        const whole = Math.floor(rawQty / product.conversion_factor);
        const sisa  = Math.round(rawQty % product.conversion_factor);
        return sisa > 0
            ? `${whole} ${product.unit_name_buy} ${sisa} ${product.unit_name_retail}`
            : `${whole} ${product.unit_name_buy}`;
    }
    return `${rawQty.toFixed(2)} ${product.unit_name_buy}`;
}

function updateStockInfo() {
    const productId  = document.getElementById('productSelect').value;
    const locationId = document.getElementById('fromLocation').value;
    const infoEl     = document.getElementById('stockInfo');
    const unitSelect = document.getElementById('unitSelect');
    const qtyHelp    = document.getElementById('qtyHelp');

    if (!productId || !locationId) { infoEl.textContent = ''; return; }

    const product = products.find(p => p.id == productId);
    if (!product) return;

    const previousUnit = unitSelect.value;
    unitSelect.innerHTML = '';
    if (product.product_units && product.product_units.length) {
        product.product_units.forEach(unit => {
            const option = new Option(unit.name, unit.id);
            option.dataset.conversion = unit.conversion_to_base;
            unitSelect.add(option);
        });
    } else {
        unitSelect.add(new Option(product.unit_name_buy, 'stored'));
    }
    if ([...unitSelect.options].some(option => option.value === previousUnit)) {
        unitSelect.value = previousUnit;
    }

    const stock = product.stocks.find(s => s.location_id == locationId);
    const rawQty = stock ? stock.quantity : 0;

    const displayStok = formatStok(rawQty, product);
    infoEl.textContent = `Stok tersedia: ${displayStok}`;

    const selectedUnit = product.product_units?.find(unit => String(unit.id) === String(unitSelect.value));
    const baseUnit = product.product_units?.find(unit => unit.is_base_unit) || product.product_units?.[product.product_units.length - 1];
    if (selectedUnit && baseUnit && selectedUnit.conversion_to_base > 1) {
        qtyHelp.textContent = `1 ${selectedUnit.name} = ${selectedUnit.conversion_to_base.toLocaleString('id-ID')} ${baseUnit.name}. Pilih satuan sesuai jumlah yang ditransfer.`;
    } else {
        qtyHelp.textContent = '';
    }

    updateQuantityLimit();
}

function updateQuantityLimit() {
    const product = products.find(p => p.id == document.getElementById('productSelect').value);
    const stock = product?.stocks.find(s => s.location_id == document.getElementById('fromLocation').value);
    const qtyInput = document.getElementById('qtyInput');
    if (!product || !stock) { qtyInput.removeAttribute('max'); return; }

    const selectedUnit = product.product_units?.find(unit => String(unit.id) === String(document.getElementById('unitSelect').value));
    const conversion = selectedUnit ? Number(selectedUnit.conversion_to_base || 1) : 1;
    const maxQty = stock.quantity / conversion;
    qtyInput.max = maxQty;
    qtyInput.step = '0.01';
}

document.getElementById('productSelect').addEventListener('change', updateStockInfo);
document.getElementById('fromLocation').addEventListener('change', updateStockInfo);
document.getElementById('unitSelect').addEventListener('change', function() {
    updateStockInfo();
    updateQuantityLimit();
});
updateStockInfo();
</script>
@endpush
@endsection
