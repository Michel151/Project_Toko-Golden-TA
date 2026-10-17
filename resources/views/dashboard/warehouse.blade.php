@extends('layouts.app')
@section('title','Dashboard Staf Gudang')
@section('page-title','Dashboard Staf Gudang')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/dashboard-donuts.css') }}?v=1">
<style>
.warehouse-section{background:#fff;padding:1rem;border-top:2px solid var(--app-border)}
.warehouse-section h6{font-weight:700;margin:0}.warehouse-section .table{font-size:.82rem;margin:0}.warehouse-section td{vertical-align:middle;padding:.55rem .5rem}
.warehouse-section[aria-labelledby="priorityHeading"] table{min-width:640px}
.warehouse-icon{width:40px;height:40px;flex:none;display:grid;place-items:center;border-radius:8px;font-size:1.2rem}.warehouse-value{font-size:1.2rem;font-weight:700}
.warehouse-line{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:.6rem;padding:.65rem 0;border-bottom:1px solid var(--app-border)}
.warehouse-line:last-child{border-bottom:0}.warehouse-empty{padding:1.2rem .4rem;text-align:center;color:var(--app-muted)}
.warehouse-chart{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.5fr);align-items:center;gap:.8rem;margin-top:1rem}
.warehouse-donut{width:min(100%,132px);aspect-ratio:1;border-radius:50%;display:grid;place-items:center;background:conic-gradient(#1f7a5a 0% var(--safe-end),#e7b548 var(--safe-end) var(--low-end),#c84b45 var(--low-end) 100%)}
.warehouse-donut-center{width:74%;height:74%;background:#fff;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center}.warehouse-donut-center strong{font-size:1.4rem}.warehouse-donut-center span{font-size:.7rem;color:var(--app-muted)}
.warehouse-legend{display:grid;grid-template-columns:10px 1fr auto;gap:.4rem;align-items:center;font-size:.8rem;padding:.4rem 0}.warehouse-swatch{width:10px;height:10px;border-radius:2px}.warehouse-legend small{display:block;font-size:.7rem}
.warehouse-location-bar{height:5px;background:#eef2ec;border-radius:2px;overflow:hidden}.warehouse-location-bar span{display:block;height:100%;background:#1f7a5a}
.warehouse-panel-stack{display:flex;flex-direction:column;gap:1rem;min-width:0}.warehouse-line>div{min-width:0;max-width:100%}
.warehouse-movement-list{max-height:320px;overflow:auto;overscroll-behavior:contain;padding-right:.25rem}
@media(min-width:1200px){
    .warehouse-dashboard{min-height:calc(100dvh - var(--warehouse-topbar-height,56px) - 2.7rem);display:flex;flex-direction:column}
    .warehouse-content-row{flex:1;display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:1rem;margin:0}
    .warehouse-content-row>.warehouse-panel-stack{display:contents}
    .warehouse-content-row>div>.warehouse-section{margin:0}
    #notifications{grid-column:1;grid-row:1/span 2}
    .warehouse-section[aria-labelledby="conditionHeading"]{grid-column:2;grid-row:1}
    .warehouse-section[aria-labelledby="locationHeading"]{grid-column:2;grid-row:2}
    .warehouse-work-queue{grid-column:1;grid-row:3;margin:0;--bs-gutter-y:0}
    .warehouse-work-queue>div:first-child{padding-left:0}.warehouse-work-queue>div:last-child{padding-right:0}
    .warehouse-section[aria-labelledby="movementHeading"]{grid-column:2;grid-row:3}
    .warehouse-transfer-summary{grid-column:1/span 2;grid-row:4}
}
@media(max-width:575px){.warehouse-stat .card-body{flex-direction:column;align-items:flex-start!important;gap:.5rem!important;padding:.85rem}.warehouse-section{padding:.85rem}}
</style>
@endpush
@section('content')
<div class="warehouse-dashboard">
<div class="warehouse-hero d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <div><div class="small text-white-50 mb-1">{{ now('Asia/Jakarta')->locale('id')->translatedFormat('l, d F Y') }}</div><h5 class="fw-bold mb-0">Selamat bekerja, {{ auth()->user()->name }}</h5></div>
    <div class="d-flex flex-wrap gap-2">
        @if(auth()->user()->canAccessRoute('purchase.receipts.create'))<a href="{{ route('purchase.receipts.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-box-arrow-in-down me-1"></i>Penerimaan</a>@endif
        <a href="{{ route('inventory.stock.index') }}" class="btn btn-sm btn-outline-light"><i class="bi bi-boxes me-1"></i>Monitoring Stok</a>
        @if(auth()->user()->canAccessRoute('inventory.transfers.create'))<a href="{{ route('inventory.transfers.create') }}" class="btn btn-sm btn-outline-light"><i class="bi bi-arrow-left-right me-1"></i>Transfer</a>@endif
        @if(auth()->user()->canAccessRoute('inventory.opnames.create'))<a href="{{ route('inventory.opnames.create') }}" class="btn btn-sm btn-success"><i class="bi bi-clipboard-check me-1"></i>Stock Opname</a>@endif
    </div>
</div>
<div class="row g-3 mb-3">
    @php $workDetail=auth()->user()->hasPermission('purchase.receipts.view') ? $pendingReceiptCount.' penerimaan · '.$draftOpnames.' opname' : $draftOpnames.' opname'; $metrics=[['Sudah Kedaluwarsa',$expiredBatchCount,'exclamation-octagon','danger','Masih memiliki stok fisik'],['Mendekati Kedaluwarsa',$expiringBatchCount,'hourglass-split','warning','Rincian dalam 30 hari'],['Stok Layak Jual Habis',$warehouseOutOfStockCount,'box2','danger','Jenis barang aktif'],['Pekerjaan Tertunda',$draftOpnames+$pendingReceiptCount,'clipboard-check','primary',$workDetail]]; @endphp
    @foreach($metrics as [$label,$value,$icon,$color,$detail])<div class="col-6 col-xl-3"><div class="card warehouse-stat h-100"><div class="card-body d-flex gap-3 align-items-center"><div class="warehouse-icon bg-{{ $color }} bg-opacity-10 text-{{ $color }}"><i class="bi bi-{{ $icon }}"></i></div><div><div class="text-muted small">{{ $label }}</div><div class="warehouse-value">{{ $value }}</div><div class="small text-muted">{{ $detail }}</div></div></div></div></div>@endforeach
</div>
<div class="row g-3 warehouse-content-row">
    <div class="col-xl-8 warehouse-panel-stack">
        <section class="warehouse-section" id="notifications" aria-labelledby="priorityHeading">
            <div class="d-flex flex-wrap justify-content-between gap-2 mb-2"><h6 id="priorityHeading">Prioritas Penanganan</h6><a href="{{ route('inventory.stock.index') }}" class="small text-decoration-none">Monitoring Stok <i class="bi bi-arrow-right"></i></a></div>
            @if($warehousePriorities->isNotEmpty())<div class="table-responsive"><table class="table table-hover"><thead><tr><th>Barang / Lokasi</th><th>Kondisi</th><th>Stok / Tanggal</th><th></th></tr></thead><tbody>
                @foreach($warehousePriorities as $priority)<tr><td><div class="fw-semibold">{{ $priority['product_name'] }}</div><div class="small text-muted">{{ $priority['location'] }}</div></td><td><span class="badge bg-{{ $priority['color'] }}">{{ $priority['label'] }}</span></td><td class="small">{{ $priority['detail'] }}</td><td class="text-end"><a href="{{ $priority['url'] }}" class="btn btn-sm btn-outline-secondary" title="Periksa stok {{ $priority['product_name'] }}" aria-label="Periksa stok {{ $priority['product_name'] }}"><i class="bi bi-arrow-up-right"></i></a></td></tr>@endforeach
            </tbody></table></div><div class="small text-muted mt-2">{{ $warehousePriorities->count() }} dari {{ $warehousePriorityCount }} kondisi perlu perhatian</div>@else<div class="warehouse-empty"><i class="bi bi-check-circle text-success me-1"></i>Tidak ada stok yang perlu penanganan.</div>@endif
        </section>
        <div class="row g-3 warehouse-work-queue">
            @if(auth()->user()->hasPermission('purchase.receipts.view'))<div class="col-lg-6"><section class="warehouse-section h-100" aria-labelledby="receiptHeading">
                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2"><h6 id="receiptHeading">Penerimaan Belum Lengkap <span class="text-muted">{{ $pendingReceiptCount }}</span></h6><a href="{{ route('purchase.receipts.index') }}" title="Lihat penerimaan" aria-label="Lihat penerimaan"><i class="bi bi-arrow-up-right"></i></a></div>
                @forelse($pendingReceipts as $order)<div class="warehouse-line"><div><div class="fw-semibold">{{ $order->po_number }}</div><div class="small text-muted">{{ $order->supplier?->name ?? '-' }} · {{ $order->remaining_items_count }} jenis tersisa</div><div class="small text-muted">{{ $order->order_date->format('d/m/Y') }} · {{ $order->status === 'partial' ? 'Diterima sebagian' : 'Belum diterima' }}</div></div>@if(auth()->user()->canAccessRoute('purchase.receipts.create'))<a href="{{ route('purchase.receipts.create',['order_id'=>$order->id]) }}" class="btn btn-sm btn-outline-primary" title="Catat penerimaan {{ $order->po_number }}" aria-label="Catat penerimaan {{ $order->po_number }}"><i class="bi bi-box-arrow-in-down"></i></a>@endif</div>@empty<div class="warehouse-empty">Tidak ada penerimaan tertunda.</div>@endforelse
            </section></div>@endif
            <div class="{{ auth()->user()->hasPermission('purchase.receipts.view') ? 'col-lg-6' : 'col-12' }}"><section class="warehouse-section h-100" aria-labelledby="opnameHeading">
                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2"><h6 id="opnameHeading">Opname Belum Selesai <span class="text-muted">{{ $draftOpnames }}</span></h6><a href="{{ route('inventory.opnames.index') }}" title="Lihat opname" aria-label="Lihat opname"><i class="bi bi-arrow-up-right"></i></a></div>
                @forelse($opnameQueue as $opname)<div class="warehouse-line"><div><div class="fw-semibold">{{ $opname->opname_number }}</div><div class="small text-muted">{{ $opname->location?->name ?? '-' }} · {{ $opname->opname_date->format('d/m/Y') }}</div></div><a href="{{ auth()->user()->canAccessRoute('inventory.opnames.edit') ? route('inventory.opnames.edit',$opname) : route('inventory.opnames.show',$opname) }}" class="btn btn-sm btn-outline-primary" title="{{ auth()->user()->canAccessRoute('inventory.opnames.edit') ? 'Lanjutkan' : 'Lihat' }} opname {{ $opname->opname_number }}" aria-label="Lihat opname {{ $opname->opname_number }}"><i class="bi bi-{{ auth()->user()->canAccessRoute('inventory.opnames.edit') ? 'pencil' : 'eye' }}"></i></a></div>@empty<div class="warehouse-empty">Tidak ada draft opname.</div>@endforelse
            </section></div>
        </div>
        <section class="warehouse-section warehouse-fill" aria-labelledby="movementHeading">
            <div class="d-flex flex-wrap justify-content-between gap-2 mb-2"><h6 id="movementHeading">Mutasi Stok Terakhir</h6><a href="{{ route('inventory.opnames.movements') }}" class="small text-decoration-none">Lihat Semua <i class="bi bi-arrow-right"></i></a></div>
            <div class="warehouse-movement-list" tabindex="0" aria-label="Daftar mutasi stok terakhir">
            @forelse($recentMovements as $movement)@php $quantity=(float)$movement->quantity; @endphp
            <div class="warehouse-line"><div><div class="fw-semibold">{{ $movement->product?->name ?? 'Barang tidak tersedia' }}</div><div class="small text-muted">{{ $movement->type_label }} · {{ $movement->location?->name ?? '-' }} · {{ $movement->reference_number ?? '-' }}</div></div><div class="text-end"><div class="fw-semibold text-{{ $quantity>0 ? 'success' : ($quantity<0 ? 'danger' : 'muted') }}">{{ $quantity>0 ? '+' : ($quantity<0 ? '-' : '') }}{{ $movement->product?->displayStock(abs($quantity)) ?? number_format(abs($quantity),2,',','.') }}</div><div class="small text-muted">{{ $movement->movement_date->format('d/m/Y') }} · {{ $movement->createdBy?->name ?? 'Sistem' }}</div></div></div>
            @empty<div class="warehouse-empty">Belum ada mutasi stok.</div>@endforelse
            </div>
        </section>
    </div>
    <div class="col-xl-4 warehouse-panel-stack">
        <section class="warehouse-section" aria-labelledby="conditionHeading"><h6 id="conditionHeading">Kondisi Stok Layak Jual</h6><div class="small text-muted mt-1">{{ $warehouseProductCount }} jenis barang aktif</div>
            @php $total=$warehouseProductCount; $safeEnd=$total ? $warehouseSafeStockCount/$total*100 : 0; $lowEnd=$total ? ($warehouseSafeStockCount+$warehouseLowStockCount)/$total*100 : 0; @endphp
            <div class="warehouse-chart"><div class="warehouse-donut" data-donut data-donut-unit="jenis barang" role="img" aria-label="{{ $warehouseSafeStockCount }} aman, {{ $warehouseLowStockCount }} rendah, {{ $warehouseOutOfStockCount }} habis" style="--safe-end:{{ $safeEnd }}%;--low-end:{{ $lowEnd }}%;{{ $total===0 ? 'background:#e8edeb;' : '' }}"><div class="warehouse-donut-center" aria-hidden="true"><strong>{{ $total }}</strong><span>jenis barang</span></div></div>
                <div>@foreach([['Aman',$warehouseSafeStockCount,'#1f7a5a'],['Rendah',$warehouseLowStockCount,'#e7b548'],['Habis',$warehouseOutOfStockCount,'#c84b45']] as [$label,$count,$color])<button type="button" class="warehouse-legend" data-donut-label="{{ $label }}" data-donut-count="{{ $count }}"><span class="warehouse-swatch" style="background:{{ $color }}" aria-hidden="true"></span><span>{{ $label }}</span><span class="text-end"><strong>{{ $count }}</strong><small class="text-muted">{{ $total ? number_format($count/$total*100,1,',','.') : '0' }}%</small></span></button>@endforeach</div>
            </div>
        </section>
        <section class="warehouse-section warehouse-fill" aria-labelledby="locationHeading"><h6 id="locationHeading">Jenis Barang per Lokasi</h6><div class="small text-muted mt-1">Layak jual / memiliki stok fisik</div>
            @forelse($stockedLocations as $location)<div class="py-2"><div class="d-flex flex-wrap justify-content-between gap-2 mb-2"><a href="{{ route('inventory.stock.index',['location_id'=>$location['id']]) }}" class="fw-semibold text-decoration-none"><i class="bi bi-geo-alt me-1"></i>{{ $location['name'] }}</a><span class="small text-muted">{{ $location['sellable'] }} / {{ $location['physical'] }} jenis</span></div><div class="warehouse-location-bar"><span style="width:{{ $warehouseProductCount ? $location['sellable']/$warehouseProductCount*100 : 0 }}%"></span></div></div>@empty<div class="warehouse-empty">Belum ada lokasi penyimpanan.</div>@endforelse
        </section>
        <section class="warehouse-section warehouse-transfer-summary"><div class="warehouse-line"><div><div class="fw-semibold">Transfer Hari Ini</div><div class="small text-muted">Dokumen transfer seluruh lokasi</div></div><a href="{{ route('inventory.transfers.index') }}" class="fw-bold text-decoration-none">{{ $transfersToday }} <i class="bi bi-arrow-up-right"></i></a></div></section>
    </div>
</div>
</div>
@endsection
@push('scripts')
<script src="{{ asset('js/dashboard-donuts.js') }}?v=1"></script>
<script>
const warehouseTopbar=document.getElementById('topbar');
new ResizeObserver(()=>document.querySelector('.warehouse-dashboard').style.setProperty('--warehouse-topbar-height',`${warehouseTopbar.offsetHeight}px`)).observe(warehouseTopbar);
</script>
@endpush
