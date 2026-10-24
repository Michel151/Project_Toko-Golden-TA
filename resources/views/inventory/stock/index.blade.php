@extends('layouts.app')
@section('title', 'Monitoring Stok')
@section('page-title', 'Monitoring Stok')

@push('styles')
<style>
    .stock-heading{background:var(--app-header);color:#fff;border-radius:8px;padding:1.15rem 1.3rem;box-shadow:0 4px 16px rgba(23,32,57,.14)}
    .stock-heading-icon{width:44px;height:44px;display:grid;place-items:center;border-radius:8px;background:rgba(200,150,12,.18);color:#ffc107;font-size:1.25rem}
    .stock-stat{border:0;border-radius:8px;box-shadow:0 3px 14px rgba(25,35,55,.06);overflow:hidden}.stock-stat::before{content:'';display:block;height:3px;background:var(--accent)}
    .stock-stat-icon{width:40px;height:40px;border-radius:10px;display:grid;place-items:center;background:var(--soft);color:var(--accent);font-size:1.05rem}
    .stock-filter{background:#fff;border:1px solid #e8ebf1;border-radius:12px;padding:.85rem 1rem;box-shadow:0 2px 10px rgba(25,35,55,.04)}
    .stock-filter label{display:block;font-size:.68rem;color:#7b8498;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.2rem}
    .stock-table th{white-space:nowrap;padding:.75rem .65rem}.stock-table td{padding:.72rem .65rem;vertical-align:middle}.stock-table tbody tr.stock-low{background:#fffaf0}.stock-table tbody tr.stock-out{background:#fff5f5}
    .stock-progress{height:4px;width:80px;margin-left:auto;margin-top:5px;background:#e9ecef}.stock-name-cell{min-width:185px}.location-stock{min-width:115px}
    .expiry-cell{min-width:170px}.expiry-list{max-width:230px}.expiry-item+.expiry-item{margin-top:.28rem}
    .status-dot{width:7px;height:7px;border-radius:50%;display:inline-block;margin-right:5px}
    @media(max-width:767px){.priority-table thead{display:none}.priority-table tbody tr{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:.25rem .75rem;padding:.75rem 0;border-bottom:1px solid var(--app-border)}.priority-table td{min-width:0!important;padding:.25rem 0;border:0;white-space:normal!important;overflow-wrap:anywhere}.priority-table td:first-child,.priority-table td:last-child{grid-column:1/-1}.priority-table td:last-child{text-align:right}.priority-table td[data-label]::before{content:attr(data-label);display:block;font-size:.7rem;color:#647067;margin-bottom:.15rem}.priority-table .badge{white-space:normal;text-align:left}.stock-stat .card-body{flex-wrap:wrap}}
    @media(min-width:769px){.stock-table .stock-name-cell{position:sticky;left:0;background:#fff;z-index:2}.stock-table tr.stock-low .stock-name-cell{background:#fffaf0}.stock-table tr.stock-out .stock-name-cell{background:#fff5f5}.stock-table thead .stock-name-cell{background:#f8f9fa;z-index:3}}
    @media(max-width:768px){.stock-table .stock-name-cell{position:sticky;left:0;min-width:135px;max-width:150px;width:150px;background:#fff;z-index:2;overflow-wrap:anywhere;box-shadow:2px 0 0 #dce3de}.stock-table tr.stock-low .stock-name-cell{background:#fffaf0}.stock-table tr.stock-out .stock-name-cell{background:#fff5f5}.stock-table thead .stock-name-cell{background:#eef2ec;z-index:3}}
</style>
@endpush

@section('content')
@php
    $canTransferStock = auth()->user()->hasPermission('inventory.transfers.create');
    $fmtStock = static fn ($qty, $product) => $product->displayStock((float) $qty);
    $fmtUnits = static function ($product) {
        if ($product->productUnits->isNotEmpty()) {
            return $product->productUnits
                ->sortByDesc('conversion_to_base')
                ->map(fn ($productUnit) => $productUnit->unit->name)
                ->implode(' / ');
        }

        return $product->can_retail
            ? $product->buyUnit->name.' / '.($product->retailUnit->name ?? '-').' (1:'.number_format($product->conversion_factor,0).')'
            : $product->buyUnit->name;
    };
@endphp

<div class="stock-heading d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <div class="d-flex align-items-center gap-3"><div class="stock-heading-icon"><i class="bi bi-boxes"></i></div><div><h5 class="fw-bold mb-1">Monitoring Persediaan</h5><div class="small text-white-50">Kondisi stok terkini pada seluruh lokasi penyimpanan</div></div></div>
    <div class="small text-white-50"><i class="bi bi-clock me-1"></i>Diperbarui {{ now()->format('H:i') }}</div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-xl"><div class="card stock-stat h-100" style="--accent:#0d6efd;--soft:#eaf2ff"><div class="card-body d-flex gap-3 align-items-center"><div class="stock-stat-icon"><i class="bi bi-box-seam"></i></div><div><div class="text-muted small">Jenis Barang</div><div class="fs-5 fw-bold">{{ $totalProducts }}</div></div></div></div></div>
    <div class="col-6 col-xl"><div class="card stock-stat h-100" style="--accent:#dc3545;--soft:#fdecef"><div class="card-body d-flex gap-3 align-items-center"><div class="stock-stat-icon"><i class="bi bi-exclamation-triangle"></i></div><div><div class="text-muted small">Layak Jual Rendah</div><div class="fs-5 fw-bold text-danger">{{ $lowStockCount }}</div></div></div></div></div>
    <div class="col-6 col-xl"><div class="card stock-stat h-100" style="--accent:#6c757d;--soft:#f0f1f2"><div class="card-body d-flex gap-3 align-items-center"><div class="stock-stat-icon"><i class="bi bi-box2"></i></div><div><div class="text-muted small">Layak Jual Habis</div><div class="fs-5 fw-bold">{{ $outOfStockCount }}</div></div></div></div></div>
    <div class="col-6 col-xl"><div class="card stock-stat h-100" style="--accent:#fd7e14;--soft:#fff0e6"><div class="card-body d-flex gap-3 align-items-center"><div class="stock-stat-icon"><i class="bi bi-calendar-x"></i></div><div><div class="text-muted small">Kedaluwarsa</div><div class="fs-5 fw-bold text-danger">{{ $expiredBatchCount }}</div><div class="small text-muted">{{ $expiringBatchCount }} hampir</div></div></div></div></div>
    <div class="col-6 col-xl"><div class="card stock-stat h-100" style="--accent:#c8960c;--soft:#fff7df"><div class="card-body d-flex gap-3 align-items-center"><div class="stock-stat-icon"><i class="bi bi-wallet2"></i></div><div><div class="text-muted small">Nilai Persediaan</div><div class="fw-bold">Rp {{ number_format($inventoryValue,0,',','.') }}</div></div></div></div></div>
</div>

<nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Tampilan persediaan">
    <a class="btn btn-sm {{ request('view') !== 'priorities' ? 'btn-success' : 'btn-outline-secondary' }}" href="{{ route('inventory.stock.index', request()->except(['view','page'])) }}"><i class="bi bi-boxes me-1"></i>Persediaan</a>
    <a class="btn btn-sm {{ request('view') === 'priorities' ? 'btn-success' : 'btn-outline-secondary' }}" href="{{ route('inventory.stock.index', array_merge(request()->except(['page']), ['view'=>'priorities'])) }}"><i class="bi bi-exclamation-triangle me-1"></i>Perlu Ditindaklanjuti <span class="badge bg-light text-dark ms-1">{{ $priorityCount }}</span></a>
</nav>
<div class="mb-3">
    <form class="app-filter-bar" method="GET">
        @if(request('view') === 'priorities')<input type="hidden" name="view" value="priorities">@endif
        <div class="app-filter-field app-filter-search"><label for="stockSearch">Cari Barang</label><input id="stockSearch" type="search" name="search" class="form-control form-control-sm" placeholder="Nama barang..." value="{{ request('search') }}"></div>
        <div class="app-filter-field"><label for="stockCategory">Kategori</label><select id="stockCategory" name="category_id" class="form-select form-select-sm"><option value="">Semua Kategori</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(request('category_id') == $category->id)>{{ $category->name }}</option>@endforeach</select></div>
        <div class="app-filter-field"><label for="stockLocation">Lokasi</label><select id="stockLocation" name="location_id" class="form-select form-select-sm"><option value="">Semua Lokasi</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected(request('location_id') == $location->id)>{{ $location->name }}</option>@endforeach</select></div>
        <div class="app-filter-field"><label for="stockStatus">Status</label><select id="stockStatus" name="status" class="form-select form-select-sm"><option value="">Semua Status</option><option value="safe" @selected(request('status')==='safe')>Aman</option><option value="low" @selected(request('status')==='low')>Stok Rendah</option><option value="out" @selected(request('status')==='out')>Stok Habis</option></select></div>
        <div class="app-filter-field"><label for="stockExpiry">Kedaluwarsa</label><select id="stockExpiry" name="expiry_status" class="form-select form-select-sm"><option value="">Semua Status</option><option value="expired" @selected(request('expiry_status')==='expired')>Sudah Kedaluwarsa</option><option value="expiring" @selected(request('expiry_status')==='expiring')>Hampir Kedaluwarsa</option><option value="safe" @selected(request('expiry_status')==='safe')>Aman</option><option value="without_date" @selected(request('expiry_status')==='without_date')>Tanpa Tanggal</option><option value="untracked" @selected(request('expiry_status')==='untracked')>Tidak Dilacak</option></select></div>
        <div class="app-filter-actions"><button class="btn btn-sm btn-primary px-3"><i class="bi bi-funnel me-1"></i>Terapkan</button>
        @if(request()->hasAny(['search','category_id','location_id','status','expiry_status']))<a href="{{ route('inventory.stock.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i></a>@endif
        </div>
    </form>
</div>

@if(request('view') === 'priorities')
    @include('inventory.stock.priorities')
@else
<div class="card table-card">
    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center"><div><h6 class="fw-bold mb-1">Daftar Persediaan</h6><small class="text-muted">Menampilkan {{ $products->firstItem() ?? 0 }}–{{ $products->lastItem() ?? 0 }} dari {{ $products->total() }} barang</small></div><span class="badge bg-light text-secondary">{{ $locations->count() }} Lokasi</span></div>
    <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover stock-table mb-0">
        <thead><tr><th class="stock-name-cell">Nama Barang</th><th>Kategori</th>@foreach($locations as $location)<th class="text-end location-stock">{{ $location->name }}</th>@endforeach<th class="expiry-cell">Kedaluwarsa</th><th class="text-end">Fisik / Layak Jual</th><th class="text-end">Minimum</th><th class="text-center">Status Layak Jual</th>@if($canTransferStock)<th class="text-center">Transfer</th>@endif</tr></thead>
        <tbody>
        @forelse($products as $product)
            @php
                $availability=$product->stockAvailability(); $total=$availability['sellable']; $minimum=(float)$product->min_stock_total;
                $isOut=$total<=0; $isLow=$total<=$minimum; $progress=$minimum>0?min(100,($total/$minimum)*100):100;
                $expiredBatches=$product->stockBatches->filter(fn($batch)=>$batch->expired_date&&$batch->expired_date->lt(today()));
                $expiringBatches=$product->stockBatches->filter(fn($batch)=>$batch->expired_date&&$batch->expired_date->gte(today())&&$batch->expired_date->lte(today()->addDays(30)));
                $nextExpiringBatch=$product->stockBatches->first(fn($batch)=>$batch->expired_date&&$batch->expired_date->gte(today()));
            @endphp
            <tr class="{{ $isOut?'stock-out':($isLow?'stock-low':'') }}">
                <td class="stock-name-cell"><a href="{{ route('inventory.stock-card.show',$product) }}" class="fw-semibold text-decoration-none">{{ $product->name }}</a><div class="text-muted" style="font-size:.72rem">{{ $fmtUnits($product) }} · <span class="text-primary">Kartu stok</span></div></td>
                <td><span class="badge bg-light text-secondary fw-normal">{{ $product->category->name ?? '-' }}</span></td>
                @foreach($locations as $location)
                    @php $atLocation=$product->stockAvailability($location->id);$qty=$atLocation['sellable'];$minLocation=(float)($product->productLocations->firstWhere('location_id',$location->id)?->min_stock??0);$locationLow=$minLocation>0&&$qty<=$minLocation; @endphp
                    <td class="text-end location-stock {{ $locationLow?'text-danger fw-semibold':'' }}"><div class="small text-muted">Fisik: {{ $fmtStock($atLocation['physical'],$product) }}</div><div class="small fw-semibold">Jual: {{ $fmtStock($qty,$product) }}</div>@if($locationLow)<div class="text-danger" style="font-size:.67rem"><i class="bi bi-exclamation-circle"></i> Min. {{ $fmtStock($minLocation,$product) }}</div>@endif</td>
                @endforeach
                <td class="expiry-cell">
                    @if($expiredBatches->isNotEmpty() || $expiringBatches->isNotEmpty())
                        <div class="expiry-list">
                            @if($expiredBatches->isNotEmpty())
                                <div class="expiry-item"><a href="{{ route('inventory.stock-card.show', ['product' => $product, 'tab' => 'expiry']) }}" class="badge bg-danger text-decoration-none">{{ $expiredBatches->count() }} kedaluwarsa</a> <span class="small text-muted">{{ $fmtStock($expiredBatches->sum(fn($batch)=>(float)$batch->quantity_remaining),$product) }}</span><div class="small text-danger">{{ $expiredBatches->first()->expiry_countdown }}</div></div>
                            @endif
                            @if($expiringBatches->isNotEmpty())
                                <div class="expiry-item"><a href="{{ route('inventory.stock-card.show', ['product' => $product, 'tab' => 'expiry']) }}" class="badge bg-warning text-dark text-decoration-none">{{ $expiringBatches->count() }} hampir</a> <span class="small text-muted">{{ $fmtStock($expiringBatches->sum(fn($batch)=>(float)$batch->quantity_remaining),$product) }}</span></div>
                            @endif
                            @if($nextExpiringBatch)
                                <div class="small text-muted">Terdekat: {{ $nextExpiringBatch->expired_date->format('d/m/Y') }} @if($nextExpiringBatch->location)<span class="text-secondary">({{ $nextExpiringBatch->location->name }})</span>@endif<div>{{ $nextExpiringBatch->expiry_countdown }}</div></div>
                            @endif
                        </div>
                    @elseif($product->is_expirable && $product->stockBatches->isNotEmpty())
                        @php $undated=$product->stockBatches->whereNull('expired_date')->count(); @endphp
                        @if($undated)<div class="small text-secondary">{{ $undated }} rincian tanpa tanggal</div>@endif
                        @if($nextExpiringBatch)<div class="small text-success">{{ $product->stockBatches->filter(fn($b)=>$b->expired_date && $b->expired_date->gt(today()->addDays(30)))->count() }} rincian aman</div><div class="small text-muted">Terdekat: {{ $nextExpiringBatch->expired_date->format('d/m/Y') }}</div><div class="small">{{ $nextExpiringBatch->expiry_countdown }}</div>@endif
                    @elseif($product->is_expirable)
                        <span class="text-muted small">Tidak ada rincian kedaluwarsa</span>
                    @else
                        <span class="text-muted small">Tidak dilacak</span>
                    @endif
                </td>
                <td class="text-end"><div class="small text-muted">Fisik: {{ $fmtStock($availability['physical'],$product) }}</div><div class="small text-danger">Kedaluwarsa: {{ $fmtStock($availability['expired'],$product) }}</div><div class="small text-muted">Layak jual</div><div class="fw-bold {{ $isLow?'text-danger':'' }}">{{ $fmtStock($total,$product) }}</div><div class="progress stock-progress"><div class="progress-bar bg-{{ $isOut?'secondary':($isLow?'danger':'success') }}" style="width:{{ $progress }}%"></div></div></td>
                <td class="text-end text-muted small">{{ $fmtStock($minimum,$product) }}</td>
                <td class="text-center">@if($isOut)<span class="badge bg-secondary"><span class="status-dot bg-white"></span>Habis</span>@elseif($isLow)<span class="badge bg-danger"><span class="status-dot bg-white"></span>Rendah</span>@else<span class="badge bg-success"><span class="status-dot bg-white"></span>Aman</span>@endif</td>
                @if($canTransferStock)
                <td class="text-center">@if(auth()->user()->canAccessRoute('inventory.transfers.create')) <a href="{{ route('inventory.transfers.create', ['product_id' => $product->id]) }}" class="btn btn-sm btn-outline-primary" title="Transfer {{ $product->name }}"><i class="bi bi-arrow-left-right"></i></a> @endif</td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ 6 + $locations->count() + ($canTransferStock ? 1 : 0) }}" class="text-center text-muted py-5"><i class="bi bi-inbox fs-2 d-block mb-2"></i>Tidak ada barang yang sesuai filter</td></tr>
        @endforelse
        </tbody>
    </table></div></div>
    @if($products->hasPages())<div class="card-footer bg-white">{{ $products->links() }}</div>@endif
</div>
@endif
@endsection
