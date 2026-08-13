@extends('layouts.app')
@section('title', 'Hasil Opname')
@section('page-title', 'Hasil Stock Opname')

@php
function fmtOpname($qty, $p) {
    if ($p->can_retail && $p->conversion_factor > 0) {
        $w = (int) floor($qty / $p->conversion_factor);
        $s = (int) round(fmod($qty, $p->conversion_factor));
        return $s > 0
            ? "{$w} {$p->buyUnit->name} {$s} {$p->retailUnit->name}"
            : "{$w} {$p->buyUnit->name}";
    }
    return number_format($qty, 2) . ' ' . $p->buyUnit->name;
}

function fmtDiff($diff, $p) {
    $abs  = abs($diff);
    $sign = $diff >= 0 ? '+' : '-';
    if ($p->can_retail && $p->conversion_factor > 0) {
        $w = (int) floor($abs / $p->conversion_factor);
        $s = (int) round(fmod($abs, $p->conversion_factor));
        return $sign . ($s > 0
            ? "{$w} {$p->buyUnit->name} {$s} {$p->retailUnit->name}"
            : "{$w} {$p->buyUnit->name}");
    }
    return ($diff >= 0 ? '+' : '') . number_format($diff, 2);
}
@endphp

@section('content')
<div class="mb-3">
    <a href="{{ route('inventory.opnames.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card table-card">
            <div class="card-body">
                <h6 class="fw-bold mb-3">{{ $opname->opname_number }}</h6>
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted">Lokasi</td><td>{{ $opname->location->name }}</td></tr>
                    <tr><td class="text-muted">Tanggal</td><td>{{ $opname->opname_date->format('d/m/Y') }}</td></tr>
                    <tr><td class="text-muted">Status</td>
                        <td>
                            <span class="badge bg-{{ $opname->status === 'completed' ? 'success' : 'warning' }}">
                                {{ $opname->status === 'completed' ? 'Selesai' : 'Draft' }}
                            </span>
                        </td>
                    </tr>
                    <tr><td class="text-muted">Dibuat Oleh</td><td>{{ $opname->createdBy->name ?? '-' }}</td></tr>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card table-card">
            <div class="card-header bg-white border-0 pt-3">
                <h6 class="fw-bold mb-0">Hasil Perhitungan</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Barang</th>
                            <th class="text-end">Stok Sistem</th>
                            <th class="text-end">Stok Fisik</th>
                            <th class="text-end">Selisih</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($opname->items as $item)
                        @php
                            $p    = $item->product;
                            $sys  = (float) $item->system_quantity;
                            $phys = (float) $item->physical_quantity;
                            $diff = (float) $item->difference;
                        @endphp
                        <tr>
                            <td>
                                {{ $p->name }}
                                <div class="text-muted" style="font-size:0.75rem">
                                    @if($p->can_retail && $p->conversion_factor > 0)
                                        {{ $p->retailUnit->name ?? '-' }}
                                    @else
                                        {{ $p->buyUnit->name }}
                                    @endif
                                </div>
                            </td>
                            <td class="text-end">{{ fmtOpname($sys, $p) }}</td>
                            <td class="text-end">{{ fmtOpname($phys, $p) }}</td>
                            <td class="text-end fw-bold {{ $diff > 0 ? 'text-success' : ($diff < 0 ? 'text-danger' : 'text-muted') }}">
                                {{ fmtDiff($diff, $p) }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection