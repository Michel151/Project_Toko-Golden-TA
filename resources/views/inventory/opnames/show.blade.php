@extends('layouts.app')
@section('title', 'Hasil Opname')
@section('page-title', 'Hasil Stock Opname')
@push('styles')
<style>
.opname-document{border-bottom:1px solid #dce3de;padding-bottom:16px;margin-bottom:16px}.opname-meta{display:flex;flex-wrap:wrap;gap:10px 28px;font-size:13px;color:#647168}.opname-meta strong{color:#25362b;font-weight:600}
.opname-summary{display:flex;flex-wrap:wrap;background:#fff;border-top:1px solid #dce3de;border-bottom:1px solid #dce3de;margin-bottom:16px}.opname-stat{flex:1;min-width:140px;padding:12px 16px;border-right:1px solid #edf0ee}.opname-stat:last-child{border-right:0}.opname-stat strong{font-size:20px;display:block}.opname-stat span{font-size:12px;color:#647168}
.opname-filters{display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin-bottom:12px}.opname-filters input{max-width:320px}.opname-filters select{width:auto;max-width:100%}
.opname-results{background:#fff;border:1px solid #dce3de;border-radius:6px;overflow:hidden}.opname-scroll{max-height:65vh;max-height:65dvh;overflow:auto}.opname-results-table{min-width:780px}.opname-results-table>thead{position:sticky;top:0;z-index:2}.opname-results-table>thead th{background:#edf2ee!important;white-space:nowrap}.opname-results-table>tbody>tr>td{padding:9px 12px;vertical-align:middle}.opname-results-table th:first-child{width:40%}.opname-results-table [hidden]{display:none!important}
.opname-quantity-result{font-variant-numeric:tabular-nums;min-width:140px}.opname-detail-toggle{display:flex;align-items:center;gap:6px;background:none;border:0;padding:3px 0 0;font-size:12px;color:#196447}.opname-detail-toggle[aria-expanded="true"] .bi-chevron-right{transform:rotate(90deg)}.opname-date-cell{background:#f7f9f8!important;padding:8px 16px!important}.opname-date-table{min-width:700px}.opname-date-table td{padding:6px 8px;vertical-align:middle}.opname-pagination{padding:12px 16px;border-top:1px solid #dce3de}.opname-pagination nav{margin:0}.opname-pagination .pagination{margin:0}
@media(max-width:575px){.opname-filters input{max-width:none;width:100%}.opname-stat{min-width:95px;padding:10px}.opname-stat strong{font-size:18px}.opname-scroll{max-height:70vh}}
</style>
@endpush
@section('content')
<div class="opname-document">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div class="d-flex flex-wrap align-items-center gap-2"><h5 class="fw-bold mb-0">{{ $opname->opname_number }}</h5><span class="badge bg-{{ $opname->status === 'completed' ? 'success' : 'warning text-dark' }}">{{ $opname->status === 'completed' ? 'Selesai' : 'Draf' }}</span></div>
        <x-back-link :fallback="route('inventory.opnames.index')" />
    </div>
    <div class="opname-meta"><span>Lokasi: <strong>{{ $opname->location->name }}</strong></span><span>Tanggal: <strong>{{ $opname->opname_date->format('d/m/Y') }}</strong></span><span>Dibuat oleh: <strong>{{ $opname->createdBy->name ?? '-' }}</strong></span></div>
    <div class="small text-muted mt-2">Petugas Opname: <strong class="text-body text-break">{{ collect($opname->participants ?? [])->pluck('name')->implode(', ') ?: 'Belum dicatat' }}</strong></div>
</div>
<div class="opname-summary" aria-label="Ringkasan seluruh barang dalam opname">
    <div class="opname-stat"><strong>{{ number_format($summary['total'],0,',','.') }}</strong><span>Total barang</span></div>
    <div class="opname-stat"><strong class="text-success">{{ number_format($summary['matched'],0,',','.') }}</strong><span>Sesuai</span></div>
    <div class="opname-stat"><strong class="text-danger">{{ number_format($summary['changed'],0,',','.') }}</strong><span>Ada selisih</span></div>
</div>
<form method="GET" action="{{ route('inventory.opnames.show',$opname) }}" class="opname-filters" role="search">
    <div class="app-filter-field"><label for="opnameResultSearch">Nama Barang</label><input id="opnameResultSearch" type="search" name="q" value="{{ $search }}" placeholder="Cari nama barang..." aria-label="Cari nama barang" class="form-control form-control-sm"></div>
    <div class="app-filter-field"><label for="opnameResultFilter">Hasil Opname</label><select id="opnameResultFilter" name="filter" aria-label="Filter hasil opname" class="form-select form-select-sm">
        @foreach(['all'=>'Semua hasil','changed'=>'Ada selisih','short'=>'Stok kurang','over'=>'Stok lebih','matched'=>'Sesuai'] as $key=>$label)<option value="{{ $key }}" @selected($filter===$key)>{{ $label }}</option>@endforeach
    </select></div>
    <div class="app-filter-field"><label for="opnameResultSize">Per Halaman</label><select id="opnameResultSize" name="per_page" aria-label="Jumlah barang per halaman" class="form-select form-select-sm">@foreach([25,50,100] as $size)<option value="{{ $size }}" @selected($perPage===$size)>{{ $size }}</option>@endforeach</select></div>
    <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Terapkan</button>
    @if($search!=='' || $filter!=='all')<a class="btn btn-sm btn-outline-secondary" href="{{ route('inventory.opnames.show',$opname) }}"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</a>@endif
</form>
<div class="opname-results">
    <div class="opname-scroll" tabindex="0" role="region" aria-label="Hasil perhitungan opname">
        <table class="table table-sm table-hover mb-0 opname-results-table">
            <caption class="visually-hidden">Hasil Perhitungan</caption>
            <thead><tr><th class="ps-3">Barang</th><th class="text-end">Stok Sistem</th><th class="text-end">Stok Fisik</th><th class="text-end pe-3">Selisih</th></tr></thead>
            <tbody>
            @forelse($items as $item)
                @php
                    $p=$item->product;
                    $units=\App\Support\OpnameQuantity::units($p);
                    $diff=(float)$item->difference;
                    $dateChanged=$item->expiryCounts->contains(fn($entry)=>round((float)$entry->physical_quantity-(float)$entry->system_quantity,4)!=0);
                @endphp
                <tr data-result-item="{{ $item->id }}">
                    <td><span class="fw-semibold">{{ $p->name }}</span>
                    @if($item->expiryCounts->isNotEmpty())<button type="button" class="opname-detail-toggle" aria-expanded="false" aria-controls="result-expiry-{{ $item->id }}"><i class="bi bi-chevron-right" aria-hidden="true"></i>{{ $item->expiryCounts->count() }} kedaluwarsa @if($diff==0 && $dateChanged)<span class="text-warning-emphasis ms-1">Rincian berubah</span>@endif</button>@endif</td>
                    <td class="text-end opname-quantity-result">{{ \App\Support\OpnameQuantity::format((float)$item->system_quantity,$units) }}</td>
                    <td class="text-end opname-quantity-result">{{ \App\Support\OpnameQuantity::format((float)$item->physical_quantity,$units) }}</td>
                    <td class="text-end fw-semibold opname-quantity-result {{ $diff>0 ? 'text-success' : ($diff<0 ? 'text-danger' : 'text-muted') }}">{{ \App\Support\OpnameQuantity::format($diff,$units,true) }}</td>
                </tr>
                @if($item->expiryCounts->isNotEmpty())
                <tr id="result-expiry-{{ $item->id }}" hidden><td colspan="4" class="opname-date-cell">
                    <table class="table table-sm mb-0 opname-date-table"><caption class="visually-hidden">Rincian Kedaluwarsa {{ $p->name }}</caption><thead><tr><th>Kedaluwarsa</th><th class="text-end">Stok Sistem</th><th class="text-end">Stok Fisik</th><th class="text-end">Selisih</th></tr></thead><tbody>
                    @foreach($item->expiryCounts as $entry)
                        @php $dateDiff=round((float)$entry->physical_quantity-(float)$entry->system_quantity,4); @endphp
                        <tr><td>{{ $entry->expired_date?->format('d/m/Y') ?? 'Tanggal belum dicatat' }} @if($entry->expired_date && $entry->expired_date->lt(today()))<span class="small text-danger ms-2">Sudah kedaluwarsa</span>@endif</td>
                            <td class="text-end">{{ \App\Support\OpnameQuantity::format((float)$entry->system_quantity,$units) }}</td><td class="text-end">{{ \App\Support\OpnameQuantity::format((float)$entry->physical_quantity,$units) }}</td>
                            <td class="text-end {{ $dateDiff>0 ? 'text-success' : ($dateDiff<0 ? 'text-danger' : 'text-muted') }}">{{ \App\Support\OpnameQuantity::format($dateDiff,$units,true) }}</td></tr>
                    @endforeach
                    </tbody></table>
                </td></tr>
                @endif
            @empty
                <tr><td colspan="4" class="text-center text-muted py-5">{{ $summary['total'] ? 'Tidak ada barang yang sesuai pencarian atau filter.' : 'Belum ada barang dalam opname ini.' }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="opname-pagination">
        <div class="small text-muted mb-2">{{ $items->total() ? $items->firstItem().'-'.$items->lastItem() : '0' }} dari {{ number_format($items->total(),0,',','.') }} barang</div>
        {{ $items->onEachSide(1)->links('inventory.opnames.pagination') }}
    </div>
</div>
@push('scripts')
<script>
document.querySelectorAll('.opname-detail-toggle').forEach(button=>button.addEventListener('click',()=>{
    const expanded=button.getAttribute('aria-expanded')!=='true';
    button.setAttribute('aria-expanded',String(expanded));
    document.getElementById(button.getAttribute('aria-controls')).hidden=!expanded;
}));
</script>
@endpush
@endsection
