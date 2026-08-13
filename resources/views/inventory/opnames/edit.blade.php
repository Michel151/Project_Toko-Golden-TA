@extends('layouts.app')
@section('title', 'Isi Stock Opname')
@section('page-title', 'Isi Jumlah Fisik — ' . $opname->opname_number)

@section('content')
<div class="mb-3 d-flex gap-2">
    <a href="{{ route('inventory.opnames.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

<div class="alert alert-info py-2 mb-3">
    <i class="bi bi-info-circle me-2"></i>
    Isi kolom <strong>Stok Fisik</strong> sesuai hasil hitung fisik di <strong>{{ $opname->location->name }}</strong>.
    Klik <strong>Simpan Draft</strong> untuk menyimpan sementara, atau <strong>Selesaikan Opname</strong> untuk mengaplikasikan ke stok sistem.
</div>

<form method="POST" action="{{ route('inventory.opnames.update', $opname) }}" id="opnameForm">
    @csrf @method('PUT')

    <div class="card table-card">
        <div class="card-header bg-white border-0 pt-3">
            <h6 class="fw-bold mb-0">{{ $opname->location->name }} — {{ $opname->opname_date->format('d/m/Y') }}</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Barang</th>
                            <th class="text-end">Stok Sistem</th>
                            <th style="width:290px">Stok Fisik</th>
                            <th class="text-end">Selisih</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($opname->items as $item)
                        @php
                            $product = $item->product;
                            $sysQty  = (float) $item->system_quantity;
                            $physQty = (float) $item->physical_quantity;
                            $diff    = (float) $item->difference;

                            if ($product->can_retail && $product->conversion_factor > 0) {
                                $sw = (int) floor($sysQty / $product->conversion_factor);
                                $ss = (int) round(fmod($sysQty, $product->conversion_factor));
                                $sysDisplay = $ss > 0
                                    ? "{$sw} {$product->buyUnit->name} {$ss} {$product->retailUnit->name}"
                                    : "{$sw} {$product->buyUnit->name}";

                                $initDus   = (int) floor($physQty / $product->conversion_factor);
                                $initBotol = (int) round(fmod($physQty, $product->conversion_factor));

                                $absDiff = abs($diff);
                                $dw = (int) floor($absDiff / $product->conversion_factor);
                                $ds = (int) round(fmod($absDiff, $product->conversion_factor));
                                $diffDisplay = ($diff >= 0 ? '+' : '-') . ($ds > 0
                                    ? "{$dw} {$product->buyUnit->name} {$ds} {$product->retailUnit->name}"
                                    : "{$dw} {$product->buyUnit->name}");
                            } else {
                                $sysDisplay  = number_format($sysQty, 2) . ' ' . $product->buyUnit->name;
                                $diffDisplay = ($diff >= 0 ? '+' : '') . number_format($diff, 2);
                            }
                        @endphp
                        <tr data-system="{{ $sysQty }}"
                            data-can-retail="{{ $product->can_retail ? 1 : 0 }}"
                            data-conversion="{{ $product->can_retail ? $product->conversion_factor : 0 }}"
                            data-buy-unit="{{ $product->buyUnit->name }}"
                            data-retail-unit="{{ optional($product->retailUnit)->name ?? '' }}">

                            <td class="fw-semibold">
                                {{ $product->name }}
                                @if($product->can_retail && $product->conversion_factor > 0)
                                <div class="text-muted" style="font-size:0.75rem">
                                    1 {{ $product->buyUnit->name }} = {{ number_format($product->conversion_factor, 0) }} {{ optional($product->retailUnit)->name }}
                                </div>
                                @endif
                            </td>

                            <td class="text-end">{{ $sysDisplay }}</td>

                            <td>
                                <input type="hidden"
                                       name="items[{{ $item->id }}][physical_quantity]"
                                       class="physical-qty-hidden"
                                       value="{{ $physQty }}">

                                @if($product->can_retail && $product->conversion_factor > 0)
                                <div class="d-flex gap-2 align-items-center">
                                    <div class="input-group input-group-sm" style="width:130px">
                                        <input type="number" class="form-control dus-input text-end"
                                               value="{{ $initDus }}" min="0" step="1" placeholder="0">
                                        <span class="input-group-text">{{ $product->buyUnit->name }}</span>
                                    </div>
                                    <div class="input-group input-group-sm" style="width:130px">
                                        <input type="number" class="form-control botol-input text-end"
                                               value="{{ $initBotol }}"
                                               min="0" max="{{ $product->conversion_factor - 1 }}"
                                               step="1" placeholder="0">
                                        <span class="input-group-text">{{ optional($product->retailUnit)->name }}</span>
                                    </div>
                                </div>
                                @else
                                <div class="input-group input-group-sm">
                                    <input type="number" class="form-control single-input text-end"
                                           value="{{ $physQty }}" min="0" step="0.01">
                                    <span class="input-group-text">{{ $product->buyUnit->name }}</span>
                                </div>
                                @endif
                            </td>

                            <td class="text-end diff-cell fw-semibold {{ $diff > 0 ? 'text-success' : ($diff < 0 ? 'text-danger' : 'text-muted') }}">
                                {{ $diffDisplay }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-save me-1"></i>Simpan Draft
        </button>
        <button type="button" class="btn btn-success" id="btnSelesaikan">
            <i class="bi bi-check-circle me-1"></i>Selesaikan Opname
        </button>
        <a href="{{ route('inventory.opnames.index') }}" class="btn btn-outline-secondary">Batal</a>
    </div>
</form>

@push('scripts')
<script>
function formatDiff(diff, conversion, buyUnit, retailUnit) {
    const abs  = Math.abs(diff);
    const sign = diff >= 0 ? '+' : '-';
    if (conversion > 0 && retailUnit) {
        const w = Math.floor(abs / conversion);
        const s = Math.round(abs % conversion);
        return sign + (s > 0 ? `${w} ${buyUnit} ${s} ${retailUnit}` : `${w} ${buyUnit}`);
    }
    return (diff >= 0 ? '+' : '') + diff.toFixed(2);
}

document.querySelectorAll('tbody tr').forEach(row => {
    const sys        = parseFloat(row.dataset.system) || 0;
    const canRetail  = row.dataset.canRetail === '1';
    const conversion = parseFloat(row.dataset.conversion) || 0;
    const buyUnit    = row.dataset.buyUnit;
    const retailUnit = row.dataset.retailUnit;
    const diffCell   = row.querySelector('.diff-cell');
    const hiddenQty  = row.querySelector('.physical-qty-hidden');

    function setDiff(total) {
        const diff = total - sys;
        diffCell.textContent = formatDiff(diff, conversion, buyUnit, retailUnit);
        diffCell.className = 'text-end diff-cell fw-semibold ' +
            (diff > 0 ? 'text-success' : diff < 0 ? 'text-danger' : 'text-muted');
    }

    if (canRetail && conversion > 0) {
        const dusInput   = row.querySelector('.dus-input');
        const botolInput = row.querySelector('.botol-input');

        function update() {
            const total = (parseFloat(dusInput.value) || 0) * conversion
                        + (parseFloat(botolInput.value) || 0);
            hiddenQty.value = total;
            setDiff(total);
        }

        dusInput.addEventListener('input', update);
        botolInput.addEventListener('input', update);

    } else {
        const singleInput = row.querySelector('.single-input');
        if (!singleInput) return;

        singleInput.addEventListener('input', function() {
            const phys = parseFloat(this.value) || 0;
            hiddenQty.value = phys;
            setDiff(phys);
        });
    }
});

document.getElementById('btnSelesaikan').addEventListener('click', function() {
    if (confirm('Selesaikan opname? Stok sistem akan disesuaikan dengan stok fisik.')) {
        document.getElementById('opnameForm').action = '{{ route("inventory.opnames.complete", $opname) }}';
        // Hapus _method PUT agar tidak konflik dengan POST complete
        const methodInput = document.querySelector('input[name="_method"]');
        if (methodInput) methodInput.remove();
        document.getElementById('opnameForm').submit();
    }
});
</script>
@endpush
@endsection