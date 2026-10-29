@extends('layouts.app')
@section('title','Kartu Stok '.$product->name)
@section('page-title','Kartu Stok Barang')

@push('styles')
<style>
    .stock-card-heading{background:var(--app-header);color:#fff;border:1px solid #18211c;border-radius:8px;padding:1rem 1.25rem}
    .stock-card-icon{width:46px;height:46px;display:grid;place-items:center;border-radius:12px;background:rgba(200,150,12,.18);color:#ffc107;font-size:1.25rem}
    .stock-card-stat{border:0;border-radius:12px;box-shadow:0 3px 12px rgba(25,35,55,.06)}
    .stock-card-filter{background:#fff;border:1px solid #e8ebf1;border-radius:12px;padding:.85rem 1rem}
    .stock-card-table th{white-space:nowrap}.stock-card-table td{vertical-align:middle;padding:.7rem}
    .stock-card-tabs{background:#fff;border:1px solid #e7eaf0;border-radius:12px;padding:.35rem;box-shadow:0 2px 10px rgba(25,35,55,.04)}
    .stock-card-tabs .nav-link{border:0;border-radius:9px;color:#697386;font-weight:600;padding:.65rem 1rem}
    .stock-card-tabs .nav-link.active{background:var(--app-accent-2);color:#fff}
    .stock-card-tabs .nav-link i{margin-right:.4rem}
    .location-icon{width:34px;height:34px;display:grid;place-items:center;border-radius:9px;background:#eef3ff;color:#0d6efd}
</style>
@endpush

@section('content')
@php
    $activeTab = request('tab', 'history');
    $totalStock = (float) $product->stocks->sum('quantity');
    $minimum = (float) $product->min_stock_total;
    $isLow = $product->stockAvailability()['sellable'] <= $minimum;
    $activeBatchCount = $product->stockBatches->filter(fn ($batch) => (float) $batch->quantity_remaining > 0)->count();
@endphp

<div class="d-flex justify-content-between mb-3">
    <x-back-link :fallback="route('inventory.stock.index')" />
    <div class="d-flex flex-wrap gap-2 justify-content-end">
        @if(auth()->user()->canAccessRoute('inventory.transfers.create')) <a href="{{ route('inventory.transfers.create', ['product_id' => $product->id]) }}" class="btn btn-sm btn-warning fw-semibold"><i class="bi bi-arrow-left-right me-1"></i>Transfer Barang</a> @endif
        @if(auth()->user()->canAccessRoute('inventory.transfers.index')) <a href="{{ route('inventory.transfers.index', ['product_id' => $product->id]) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-clock-history me-1"></i>Riwayat Transfer</a> @endif
        <a href="{{ route('inventory.stock-card.pdf',['product'=>$product]+request()->only(['from','to','location_id'])) }}" target="_blank" class="btn btn-sm btn-outline-danger"><i class="bi bi-file-pdf me-1"></i>Cetak PDF</a>
    </div>
</div>

<div class="stock-card-heading d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <div class="d-flex gap-3 align-items-center">
        <div class="stock-card-icon"><i class="bi bi-journal-text"></i></div>
        <div>
            <div class="small text-white-50">Kartu Stok</div>
            <h4 class="fw-bold mb-1">{{ $product->name }}</h4>
            <div class="small text-white-50">{{ $product->category->name ?? '-' }} &middot; Satuan stok: {{ $product->can_retail ? ($product->retailUnit->name ?? $product->buyUnit->name) : $product->buyUnit->name }}</div>
        </div>
    </div>
    <div class="text-end">
        <div class="small text-white-50">Periode</div>
        <div class="fw-semibold">{{ date('d/m/Y',strtotime($from)) }} - {{ date('d/m/Y',strtotime($to)) }}</div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card stock-card-stat"><div class="card-body"><div class="text-muted small">Saldo Awal</div><div class="fw-bold">{{ $product->displayStock($openingBalance) }}</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card stock-card-stat"><div class="card-body"><div class="text-muted small">Barang Masuk</div><div class="fw-bold text-success">+{{ $product->displayStock($totalIn) }}</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card stock-card-stat"><div class="card-body"><div class="text-muted small">Barang Keluar</div><div class="fw-bold text-danger">-{{ $product->displayStock($totalOut) }}</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card stock-card-stat"><div class="card-body"><div class="text-muted small">Saldo Akhir</div><div class="fw-bold text-primary">{{ $product->displayStock($closingBalance) }}</div></div></div></div>
</div>

<ul class="nav stock-card-tabs mb-3" role="tablist">
    <li class="nav-item"><a class="nav-link {{ $activeTab === 'stock' ? 'active' : '' }}" href="{{ route('inventory.stock-card.show',['product'=>$product,'tab'=>'stock']) }}"><i class="bi bi-geo-alt"></i>Stok per Lokasi <span class="badge bg-light text-secondary ms-1">{{ $product->productLocations->count() }}</span></a></li>
    @if($product->is_expirable)
    <li class="nav-item"><a class="nav-link {{ $activeTab === 'expiry' ? 'active' : '' }}" href="{{ route('inventory.stock-card.show',['product'=>$product,'tab'=>'expiry']) }}"><i class="bi bi-calendar2-week"></i>Kedaluwarsa <span class="badge bg-light text-secondary ms-1">{{ $activeBatchCount }}</span></a></li>
    @endif
    <li class="nav-item"><a class="nav-link {{ $activeTab === 'history' ? 'active' : '' }}" href="{{ route('inventory.stock-card.show',['product'=>$product]+request()->only(['from','to','location_id'])) }}"><i class="bi bi-clock-history"></i>Riwayat Mutasi <span class="badge bg-light text-secondary ms-1">{{ $movements->total() }}</span></a></li>
</ul>

@if($activeTab === 'stock')
<div class="card table-card">
    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between">
        <div><h6 class="fw-bold mb-1">Distribusi Stok per Lokasi</h6><small class="text-muted">Jumlah persediaan dan batas minimum setiap gudang</small></div>
        <span class="badge bg-{{ $isLow ? 'danger' : 'success' }} align-self-center">Total: {{ $product->displayStock($totalStock) }}</span>
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th>Lokasi</th><th class="text-end">Fisik / Layak Jual</th><th class="text-end">Minimum</th><th style="width:25%">Tingkat Stok</th><th class="text-center">Status</th></tr></thead>
        <tbody>
        @forelse($product->productLocations as $productLocation)
            @php
                $quantity = (float) ($product->stocks->firstWhere('location_id',$productLocation->location_id)?->quantity ?? 0);
                $locationMin = (float) $productLocation->min_stock;
                $sellableQuantity = $product->stockAvailability($productLocation->location_id)['sellable'];
                $locationLow = $sellableQuantity <= $locationMin;
                $level = $locationMin > 0 ? min(100,($sellableQuantity / $locationMin) * 100) : 100;
            @endphp
            <tr><td><div class="d-flex align-items-center gap-2"><div class="location-icon"><i class="bi bi-building"></i></div><div class="fw-semibold">{{ $productLocation->location->name }}</div></div></td><td class="text-end fw-bold {{ $locationLow ? 'text-danger' : '' }}">{{ $product->displayStock($quantity) }}<div class="small text-muted">Jual: {{ $product->displayStock($sellableQuantity) }}</div></td><td class="text-end text-muted">{{ $product->displayStock($locationMin) }}</td><td><div class="progress" style="height:7px"><div class="progress-bar bg-{{ $locationLow ? 'danger' : 'success' }}" style="width:{{ $level }}%"></div></div></td><td class="text-center"><span class="badge bg-{{ $locationLow ? 'danger' : 'success' }}">{{ $locationLow ? 'Rendah' : 'Aman' }}</span></td></tr>
        @empty
            <tr><td colspan="5" class="text-center text-muted py-5"><i class="bi bi-inbox fs-2 d-block mb-2"></i>Belum ada lokasi stok untuk barang ini.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
@elseif($activeTab === 'expiry' && $product->is_expirable)
@php
    $expiryBatchGroups = $product->stockBatches
        ->filter(fn ($batch) => (float) $batch->quantity_remaining > 0)
        ->groupBy(fn ($batch) => $batch->location_id.'|'.($batch->expired_date?->toDateString() ?? 'none'))
        ->map(function ($batches) {
            $first = $batches->first();

            return [
                'location' => $first->location,
                'expired_date' => $first->expired_date,
                'quantity_initial' => $batches->sum(fn ($batch) => (float) $batch->quantity_initial),
                'quantity_remaining' => $batches->sum(fn ($batch) => (float) $batch->quantity_remaining),
                'received_dates' => $batches->pluck('received_date')->filter()->sort()->unique(),
                'batch_count' => $batches->count(),
                'batches' => $batches->sortBy([['received_date', 'asc'], ['id', 'asc']])->values(),
                'status' => $first->expiry_status,
                'countdown' => $first->expiry_countdown,
            ];
        })
        ->sortBy([
            ['expired_date', 'asc'],
            ['location.name', 'asc'],
        ]);
@endphp
@include('inventory.stock.availability')
<div class="card table-card">
    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between">
        <div><h6 class="fw-bold mb-1">Kedaluwarsa</h6><small class="text-muted">Ditampilkan per lokasi dan tanggal kedaluwarsa. Rincian tanpa sisa stok disembunyikan.</small></div>
        <span class="badge bg-warning text-dark align-self-center">30 hari = hampir kedaluwarsa</span>
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th>Lokasi</th><th>Riwayat Masuk</th><th>No. PO</th><th>Kedaluwarsa</th><th class="text-end">Masuk ke Lokasi</th><th class="text-end">Sisa Fisik / Layak Jual</th><th class="text-center">Status</th></tr></thead>
        <tbody>
        @forelse($expiryBatchGroups as $group)
            @php
                $status = $group['status'];
                $badge = $status === 'Kedaluwarsa' ? 'danger' : ($status === 'Hampir Kedaluwarsa' ? 'warning text-dark' : ($status === 'Tidak Ada Kedaluwarsa' ? 'secondary' : 'success'));
                $receivedDates = $group['received_dates'];
                $receivedLabel = $receivedDates->count() > 1
                    ? $receivedDates->first()?->format('d/m/Y').' - '.$receivedDates->last()?->format('d/m/Y')
                    : $receivedDates->first()?->format('d/m/Y');
                $collapseId = 'stockCardExpiryBatchDetail'.$loop->index;
            @endphp
            <tr>
                <td>{{ $group['location']->name }}</td>
                <td>{{ $receivedLabel ?? '-' }} @if($group['batch_count'] > 1)<button type="button" class="btn btn-sm btn-light border py-0 px-2 ms-1" data-bs-toggle="collapse" data-bs-target="#{{ $collapseId }}" aria-expanded="false" aria-controls="{{ $collapseId }}">{{ $group['batch_count'] }} rincian <i class="bi bi-chevron-down small"></i></button>@endif</td>
                <td>
                    @php $batchOrders = $group['batches']->map(fn ($batch) => $batch->purchaseReceiptItem?->purchaseReceipt?->purchaseOrder)->unique(fn ($order) => $order?->id ?? 0); @endphp
                    @foreach($batchOrders as $batchOrder)<div>@include('inventory.stock-card.batch-po')</div>@endforeach
                </td>
                <td>{{ $group['expired_date']?->format('d/m/Y') ?? '-' }}<div class="small {{ $status === 'Kedaluwarsa' ? 'text-danger' : 'text-muted' }}">{{ $group['countdown'] }}</div></td>
                <td class="text-end">{{ $product->displayStock((float) $group['quantity_initial']) }}</td>
                <td class="text-end fw-semibold">{{ $product->displayStock((float) $group['quantity_remaining']) }}<div class="small text-muted">Jual: {{ $product->displayStock($status === 'Kedaluwarsa' ? 0 : (float) $group['quantity_remaining']) }}</div></td>
                <td class="text-center"><span class="badge bg-{{ $badge }}">{{ $status }}</span></td>
            </tr>
            @if($group['batch_count'] > 1)
            <tr class="collapse" id="{{ $collapseId }}">
                <td colspan="7" class="bg-light"><div class="table-responsive"><table class="table table-sm mb-0 align-middle">
                    <thead><tr><th>No. PO</th><th>Tanggal Terima</th><th class="text-end">Awal</th><th class="text-end">Sisa</th><th class="text-center">Status</th></tr></thead>
                    <tbody>
                    @foreach($group['batches'] as $batch)
                        @php $detailStatus = $batch->expiry_status; $detailBadge = $detailStatus === 'Kedaluwarsa' ? 'danger' : ($detailStatus === 'Hampir Kedaluwarsa' ? 'warning text-dark' : ($status === 'Tidak Ada Kedaluwarsa' ? 'secondary' : 'success')); @endphp
                        <tr><td>@include('inventory.stock-card.batch-po', ['batchOrder' => $batch->purchaseReceiptItem?->purchaseReceipt?->purchaseOrder])</td><td>{{ $batch->received_date?->format('d/m/Y') }}</td><td class="text-end">{{ $product->displayStock((float) $batch->quantity_initial) }}</td><td class="text-end fw-semibold">{{ $product->displayStock((float) $batch->quantity_remaining) }}</td><td class="text-center"><span class="badge bg-{{ $detailBadge }}">{{ $detailStatus }}</span></td></tr>
                    @endforeach
                    </tbody>
                </table></div></td>
            </tr>
            @endif
        @empty
            <tr><td colspan="7" class="text-center text-muted py-5"><i class="bi bi-inbox fs-2 d-block mb-2"></i>Tidak ada rincian kedaluwarsa yang masih memiliki sisa stok.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
@else
<div class="mb-3">
    <form class="app-unified-filter app-filter-bar">
        <div class="app-filter-field"><label for="stockCardLocation">Lokasi</label><select id="stockCardLocation" name="location_id" class="form-select form-select-sm"><option value="">Semua Lokasi</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected($locationId===$location->id)>{{ $location->name }}</option>@endforeach</select></div>
        <div class="app-filter-field"><label for="stockCardFrom">Dari Tanggal</label><input id="stockCardFrom" type="date" name="from" value="{{ $from }}" class="form-control form-control-sm"></div>
        <div class="app-filter-field"><label for="stockCardTo">Sampai Tanggal</label><input id="stockCardTo" type="date" name="to" value="{{ $to }}" class="form-control form-control-sm"></div>
        <div class="app-filter-actions"><button class="btn btn-sm btn-primary px-3"><i class="bi bi-funnel me-1"></i>Terapkan</button>
        <a href="{{ route('inventory.stock-card.show',$product) }}" class="btn btn-sm btn-outline-secondary" title="Reset filter" aria-label="Reset filter"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset</a></div>
    </form>
</div>
<div class="card table-card">
    <div class="card-header bg-white border-0 py-3"><h6 class="fw-bold mb-1">Mutasi Persediaan</h6><small class="text-muted">{{ $movements->total() }} transaksi dalam periode terpilih</small></div>
    <div class="table-responsive"><table class="table table-hover stock-card-table app-mobile-records mb-0" data-mobile-records>
        <thead><tr><th>Tanggal</th><th>Referensi</th><th>Keterangan</th><th>Lokasi</th><th class="text-end">Masuk</th><th class="text-end">Keluar</th><th class="text-end">Saldo</th><th>Petugas</th></tr></thead>
        <tbody>
        @forelse($movements as $movement)
            @php $quantity = (float) $movement->quantity; @endphp
            <tr><td class="text-nowrap">{{ $movement->movement_date->format('d/m/Y') }}<div class="small text-muted">{{ $movement->created_at->format('H:i') }}</div></td><td class="font-monospace small">{{ $movement->reference_number ?? '-' }}</td><td><div class="fw-semibold">{{ $movement->type_label }}</div>@if($movement->notes)<small class="text-muted">{{ $movement->notes }}</small>@endif</td><td>{{ $movement->location->name }}</td><td class="text-end fw-semibold text-success">{{ $quantity > 0 ? $product->displayStock($quantity) : '-' }}</td><td class="text-end fw-semibold text-danger">{{ $quantity < 0 ? $product->displayStock(abs($quantity)) : '-' }}</td><td class="text-end fw-bold">{{ $product->displayStock((float) $movement->card_balance) }}</td><td class="small text-muted">{{ $movement->createdBy->name ?? 'Sistem' }}</td></tr>
        @empty
            <tr><td colspan="8" class="text-center text-muted py-5"><i class="bi bi-inbox fs-2 d-block mb-2"></i>Tidak ada mutasi pada periode ini</td></tr>
        @endforelse
        </tbody>
        <tfoot class="table-light fw-bold"><tr><td colspan="4">TOTAL / SALDO AKHIR</td><td class="text-end text-success">{{ $product->displayStock($totalIn) }}</td><td class="text-end text-danger">{{ $product->displayStock($totalOut) }}</td><td class="text-end">{{ $product->displayStock($closingBalance) }}</td><td></td></tr></tfoot>
    </table></div>
    @if($movements->hasPages())<div class="card-footer bg-white">{{ $movements->links() }}</div>@endif
</div>
@endif
@endsection
