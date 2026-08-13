@extends('layouts.app')
@section('title', $product->name)
@section('page-title', 'Detail Barang')

@push('styles')
<style>
    .product-hero{background:linear-gradient(120deg,#172039,#27324a);color:#fff;border-radius:16px;padding:1.3rem 1.4rem;box-shadow:0 4px 18px rgba(23,32,57,.15)}
    .product-avatar{width:58px;height:58px;border-radius:15px;background:rgba(200,150,12,.18);color:#ffc107;display:grid;place-items:center;font-size:1.7rem}
    .product-kpi{min-width:135px;padding:.7rem .9rem;border-radius:10px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.08)}
    .product-tabs{background:#fff;border:1px solid #e7eaf0;border-radius:12px;padding:.35rem;box-shadow:0 2px 10px rgba(25,35,55,.04)}
    .product-tabs .nav-link{border:0;border-radius:9px;color:#697386;font-weight:600;padding:.65rem 1rem}.product-tabs .nav-link.active{background:#27324a;color:#fff}.product-tabs .nav-link i{margin-right:.4rem}
    .detail-card{border:0;border-radius:13px;box-shadow:0 3px 14px rgba(25,35,55,.06)}
    .detail-row{display:flex;justify-content:space-between;gap:1rem;padding:.72rem 0;border-bottom:1px solid #edf0f4}.detail-row:last-child{border-bottom:0}.detail-label{color:#7b8498}.detail-value{font-weight:600;text-align:right}
    .price-box{border-radius:12px;padding:1rem;background:#f8f9fb;border:1px solid #e9ecf2}.location-icon{width:34px;height:34px;display:grid;place-items:center;border-radius:9px;background:#eef3ff;color:#0d6efd}
    .history-filter{background:#f8f9fb;border-top:1px solid #edf0f4;border-bottom:1px solid #edf0f4}.history-table th{white-space:nowrap}.history-table td{vertical-align:middle;padding:.7rem}
    .code-preview{min-height:260px;border:1px dashed #d8dee9;border-radius:14px;background:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:1.5rem}
    #productBarcode{max-width:100%;height:92px}.qr-box{width:190px;height:190px;display:grid;place-items:center}.qr-box img,.qr-box canvas{max-width:100%;height:auto!important}
</style>
@endpush

@section('content')
@php
    $activeTab=request('tab','info');
    $totalStock=(float)$product->stocks->sum('quantity');
    $minimum=(float)$product->min_stock_total;
    $isLow=$totalStock<=$minimum;
    $purchaseUnit=$product->productUnits->firstWhere('is_default_purchase', true);
    $baseCost=$purchaseUnit && (float)$purchaseUnit->conversion_to_base>0 ? (float)$purchaseUnit->buy_price/(float)$purchaseUnit->conversion_to_base : (float)$product->buy_price;
    $stockValue=$totalStock*$baseCost;
@endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <a href="{{ route('master.products.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
    <a href="{{ route('master.products.edit',$product) }}" class="btn btn-sm btn-warning"><i class="bi bi-pencil me-1"></i>Edit Barang</a>
</div>

<div class="product-hero mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div class="d-flex align-items-center gap-3"><div class="product-avatar"><i class="bi bi-box-seam"></i></div><div><span class="badge bg-warning text-dark mb-2">{{ $product->category->name ?? 'Tanpa Kategori' }}</span><h4 class="fw-bold mb-1">{{ $product->name }}</h4><div class="text-white-50 small">{{ $product->productUnits->pluck('unit.name')->filter()->implode(' / ') ?: $product->buyUnit->name }}</div></div></div>
        <div class="d-flex flex-wrap gap-2">
            <div class="product-kpi"><div class="small text-white-50">Stok Saat Ini</div><div class="fw-bold">{{ $product->displayStock($totalStock) }}</div></div>
            <div class="product-kpi"><div class="small text-white-50">Nilai Stok</div><div class="fw-bold">Rp {{ number_format($stockValue,0,',','.') }}</div></div>
            <div class="product-kpi"><div class="small text-white-50">Status</div><div class="fw-bold text-{{ $isLow?'warning':'success' }}"><i class="bi bi-{{ $isLow?'exclamation-triangle':'check-circle' }} me-1"></i>{{ $isLow?'Stok Rendah':'Aman' }}</div></div>
        </div>
    </div>
</div>

<ul class="nav product-tabs mb-3" role="tablist">
    <li class="nav-item"><a class="nav-link {{ $activeTab==='info'?'active':'' }}" href="{{ route('master.products.show',['product'=>$product,'tab'=>'info']) }}"><i class="bi bi-info-circle"></i>Informasi Barang</a></li>
    <li class="nav-item"><a class="nav-link {{ $activeTab==='stock'?'active':'' }}" href="{{ route('master.products.show',['product'=>$product,'tab'=>'stock']) }}"><i class="bi bi-geo-alt"></i>Stok per Lokasi <span class="badge bg-light text-secondary ms-1">{{ $product->productLocations->count() }}</span></a></li>
    @if($product->is_expirable)<li class="nav-item"><a class="nav-link {{ $activeTab==='expiry'?'active':'' }}" href="{{ route('master.products.show',['product'=>$product,'tab'=>'expiry']) }}"><i class="bi bi-calendar2-week"></i>Batch Kadaluarsa <span class="badge bg-light text-secondary ms-1">{{ $product->stockBatches->count() }}</span></a></li>@endif
    <li class="nav-item"><a class="nav-link {{ $activeTab==='code'?'active':'' }}" href="{{ route('master.products.show',['product'=>$product,'tab'=>'code']) }}"><i class="bi bi-qr-code"></i>Barcode & QR</a></li>
    <li class="nav-item"><a class="nav-link {{ $activeTab==='history'?'active':'' }}" href="{{ route('master.products.show',['product'=>$product,'tab'=>'history']) }}"><i class="bi bi-clock-history"></i>Riwayat Mutasi <span class="badge bg-light text-secondary ms-1">{{ $movementCount }}</span></a></li>
</ul>

@if($activeTab==='info')
<div class="row g-3">
    <div class="col-lg-7"><div class="card detail-card h-100"><div class="card-header bg-white border-0 pt-3"><h6 class="fw-bold mb-0"><i class="bi bi-card-list text-primary me-2"></i>Informasi Dasar</h6></div><div class="card-body">
        <div class="detail-row"><span class="detail-label">Nama Barang</span><span class="detail-value">{{ $product->name }}</span></div>
        <div class="detail-row"><span class="detail-label">Kategori</span><span class="detail-value">{{ $product->category->name ?? '-' }}</span></div>
        <div class="detail-row"><span class="detail-label">Kadaluarsa</span><span class="detail-value">{{ $product->is_expirable ? 'Dicatat per batch' : 'Tidak digunakan' }}</span></div>
        <div class="detail-row"><span class="detail-label">Satuan Produk</span><span class="detail-value">{{ $product->productUnits->pluck('unit.name')->filter()->implode(' / ') ?: $product->buyUnit->name }}</span></div>
        @foreach($product->productUnits->sortByDesc('conversion_to_base') as $productUnit)<div class="detail-row"><span class="detail-label">{{ $productUnit->unit->name }}</span><span class="detail-value">1 {{ $productUnit->unit->name }} = {{ number_format((float)$productUnit->conversion_to_base,0,',','.') }} {{ $product->productUnits->firstWhere('is_base_unit', true)?->unit?->name ?? 'satuan dasar' }}</span></div>@endforeach
        <div class="detail-row"><span class="detail-label">Minimum Stok Total</span><span class="detail-value">{{ $product->displayStock($minimum) }}</span></div>
    </div></div></div>
    <div class="col-lg-5"><div class="card detail-card h-100"><div class="card-header bg-white border-0 pt-3"><h6 class="fw-bold mb-0"><i class="bi bi-tags text-warning me-2"></i>Informasi Harga</h6></div><div class="card-body d-flex flex-column gap-3">
        @foreach($product->productUnits->sortByDesc('conversion_to_base') as $productUnit)<div class="price-box"><div class="small text-muted">{{ $productUnit->unit->name }}</div><div class="fs-5 fw-bold text-success">Rp {{ number_format($productUnit->sell_price,0,',','.') }}</div><small class="text-muted">Beli: Rp {{ number_format($productUnit->buy_price,0,',','.') }}</small></div>@endforeach
    </div></div></div>
</div>
@elseif($activeTab==='stock')
<div class="card detail-card"><div class="card-header bg-white border-0 py-3 d-flex justify-content-between"><div><h6 class="fw-bold mb-1">Distribusi Stok per Lokasi</h6><small class="text-muted">Jumlah persediaan dan batas minimum setiap gudang</small></div><span class="badge bg-{{ $isLow?'danger':'success' }} align-self-center">Total: {{ $product->displayStock($totalStock) }}</span></div><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Lokasi</th><th class="text-end">Stok Saat Ini</th><th class="text-end">Minimum</th><th style="width:25%">Tingkat Stok</th><th class="text-center">Status</th></tr></thead><tbody>
@foreach($product->productLocations as $productLocation)
@php $quantity=(float)($product->stocks->firstWhere('location_id',$productLocation->location_id)?->quantity??0);$locationMin=(float)$productLocation->min_stock;$locationLow=$quantity<=$locationMin;$level=$locationMin>0?min(100,($quantity/$locationMin)*100):100; @endphp
<tr><td><div class="d-flex align-items-center gap-2"><div class="location-icon"><i class="bi bi-building"></i></div><div class="fw-semibold">{{ $productLocation->location->name }}</div></div></td><td class="text-end fw-bold {{ $locationLow?'text-danger':'' }}">{{ $product->displayStock($quantity) }}</td><td class="text-end text-muted">{{ $product->displayStock($locationMin) }}</td><td><div class="progress" style="height:7px"><div class="progress-bar bg-{{ $locationLow?'danger':'success' }}" style="width:{{ $level }}%"></div></div></td><td class="text-center"><span class="badge bg-{{ $locationLow?'danger':'success' }}">{{ $locationLow?'Rendah':'Aman' }}</span></td></tr>
@endforeach
</tbody></table></div></div>
@elseif($activeTab==='expiry' && $product->is_expirable)
<div class="card detail-card">
    <div class="card-header bg-white border-0 py-3 d-flex justify-content-between">
        <div><h6 class="fw-bold mb-1">Batch Kadaluarsa</h6><small class="text-muted">Stok layak jual dihitung dari batch yang belum kadaluarsa.</small></div>
        <span class="badge bg-warning text-dark align-self-center">30 hari = hampir kadaluarsa</span>
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Lokasi</th><th>Tanggal Terima</th><th>Kadaluarsa</th><th class="text-end">Awal</th><th class="text-end">Sisa</th><th class="text-center">Status</th></tr></thead><tbody>
    @forelse($product->stockBatches->sortBy([['expired_date','asc'], ['received_date','asc']]) as $batch)
    @php
        $status=$batch->expiry_status;
        $badge=$status==='Kadaluarsa'?'danger':($status==='Hampir Kadaluarsa'?'warning text-dark':'success');
    @endphp
    <tr>
        <td>{{ $batch->location->name }}</td>
        <td>{{ $batch->received_date?->format('d/m/Y') }}</td>
        <td>{{ $batch->expired_date?->format('d/m/Y') ?? '-' }}</td>
        <td class="text-end">{{ $product->displayStock((float)$batch->quantity_initial) }}</td>
        <td class="text-end fw-semibold">{{ $product->displayStock((float)$batch->quantity_remaining) }}</td>
        <td class="text-center"><span class="badge bg-{{ $badge }}">{{ $status }}</span></td>
    </tr>
    @empty
    <tr><td colspan="6" class="text-center text-muted py-5"><i class="bi bi-inbox fs-2 d-block mb-2"></i>Belum ada batch kadaluarsa.</td></tr>
    @endforelse
    </tbody></table></div>
</div>
@elseif($activeTab==='code')
<div class="card detail-card">
    <div class="card-header bg-white border-0 py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div><h6 class="fw-bold mb-1"><i class="bi bi-upc-scan text-primary me-2"></i>Identitas Barang</h6><small class="text-muted">Gunakan barcode untuk kasir dan QR untuk membuka detail barang.</small></div>
        <a href="{{ route('master.products.label',$product) }}" target="_blank" class="btn btn-primary"><i class="bi bi-printer me-1"></i>Cetak Label</a>
    </div>
    <div class="card-body"><div class="row g-3">
        <div class="col-lg-7"><div class="code-preview"><div class="small text-muted mb-2">BARCODE CODE 128</div><svg id="productBarcode"></svg><div class="fw-bold mt-2">{{ $product->name }}</div></div></div>
        <div class="col-lg-5"><div class="code-preview"><div class="small text-muted mb-2">QR DETAIL BARANG</div><div id="productQr" class="qr-box"></div><div class="small text-muted mt-2 text-center">Pindai untuk membuka halaman detail</div></div></div>
        <div class="col-12"><div class="alert alert-light border d-flex flex-wrap justify-content-between align-items-center gap-2 mb-0"><div><div class="small text-muted">Kode barang</div><strong class="font-monospace" id="barcodeText">{{ $product->barcode }}</strong></div><button type="button" class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('barcodeText').textContent)"><i class="bi bi-copy me-1"></i>Salin Kode</button></div></div>
    </div></div>
</div>
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    JsBarcode('#productBarcode', @json($product->barcode), {format:'CODE128', width:2, height:70, displayValue:true, fontSize:16, margin:8});
    new QRCode(document.getElementById('productQr'), {text:@json(route('master.products.show',$product)), width:180, height:180, colorDark:'#172039'});
});
</script>
@endpush
@else
<div class="card detail-card" id="stock-history">
    <div class="card-header bg-white border-0 py-3"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2"><div><h6 class="fw-bold mb-1"><i class="bi bi-clock-history text-primary me-2"></i>Riwayat Barang Masuk & Keluar</h6><small class="text-muted">Jejak audit seluruh perubahan stok</small></div><div class="d-flex gap-2"><a href="{{ route('inventory.stock-card.show',$product) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-journal-text me-1"></i>Kartu Stok</a><span class="badge bg-success bg-opacity-10 text-success px-3 py-2"><i class="bi bi-arrow-down-left me-1"></i>Masuk {{ $product->displayStock($totalMovementIn) }}</span><span class="badge bg-danger bg-opacity-10 text-danger px-3 py-2"><i class="bi bi-arrow-up-right me-1"></i>Keluar {{ $product->displayStock($totalMovementOut) }}</span></div></div></div>
    <div class="card-body history-filter"><form method="GET" action="{{ route('master.products.show',$product) }}" class="row g-2 align-items-end"><input type="hidden" name="tab" value="history">
        <div class="col-md-2"><label class="form-label small text-muted">Lokasi</label><select name="location_id" class="form-select form-select-sm"><option value="">Semua Lokasi</option>@foreach($movementLocations as $location)<option value="{{ $location->id }}" @selected(request('location_id')==$location->id)>{{ $location->name }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label small text-muted">Jenis Transaksi</label><select name="movement_type" class="form-select form-select-sm"><option value="">Semua Jenis</option><option value="purchase" @selected(request('movement_type')==='purchase')>Penerimaan</option><option value="sale" @selected(request('movement_type')==='sale')>Penjualan</option><option value="transfer_in" @selected(request('movement_type')==='transfer_in')>Transfer Masuk</option><option value="transfer_out" @selected(request('movement_type')==='transfer_out')>Transfer Keluar</option><option value="opname_adjustment" @selected(request('movement_type')==='opname_adjustment')>Stock Opname</option></select></div>
        <div class="col-md-2"><label class="form-label small text-muted">Arah</label><select name="direction" class="form-select form-select-sm"><option value="">Masuk & Keluar</option><option value="in" @selected(request('direction')==='in')>Masuk</option><option value="out" @selected(request('direction')==='out')>Keluar</option></select></div>
        <div class="col-md-2"><label class="form-label small text-muted">Dari</label><input type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm"></div><div class="col-md-2"><label class="form-label small text-muted">Sampai</label><input type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm"></div>
        <div class="col-md-2 d-flex gap-2"><button class="btn btn-sm btn-primary flex-grow-1"><i class="bi bi-funnel me-1"></i>Filter</button><a href="{{ route('master.products.show',['product'=>$product,'tab'=>'history']) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i></a></div>
    </form></div>
    <div class="table-responsive"><table class="table table-hover history-table mb-0"><thead><tr><th>Tanggal</th><th>Lokasi</th><th>Jenis</th><th>Referensi</th><th class="text-end">Perubahan</th><th class="text-end">Saldo Awal</th><th class="text-end">Saldo Akhir</th><th>Petugas</th></tr></thead><tbody>
    @forelse($movements as $movement)@php $quantity=(float)$movement->quantity; @endphp<tr><td class="text-nowrap"><div>{{ $movement->movement_date->format('d/m/Y') }}</div><small class="text-muted">{{ $movement->created_at->format('H:i') }}</small></td><td><i class="bi bi-geo-alt text-muted me-1"></i>{{ $movement->location->name }}</td><td><span class="badge bg-{{ $quantity>0?'success':'danger' }} bg-opacity-10 text-{{ $quantity>0?'success':'danger' }}">{{ $movement->type_label }}</span>@if($movement->notes)<div class="text-muted mt-1" style="font-size:.7rem">{{ $movement->notes }}</div>@endif</td><td class="font-monospace small">{{ $movement->reference_number??'-' }}</td><td class="text-end fw-bold text-{{ $quantity>0?'success':'danger' }}">{{ $quantity>0?'+':'-' }}{{ $product->displayStock(abs($quantity)) }}</td><td class="text-end small text-muted">{{ $product->displayStock((float)$movement->balance_before) }}</td><td class="text-end small fw-semibold">{{ $product->displayStock((float)$movement->balance_after) }}</td><td class="small text-muted">{{ $movement->createdBy->name??'Sistem' }}</td></tr>
    @empty<tr><td colspan="8" class="text-center text-muted py-5"><i class="bi bi-inbox fs-2 d-block mb-2"></i>Belum ada riwayat mutasi</td></tr>@endforelse
    </tbody></table></div>
    @if($movements->hasPages())<div class="card-footer bg-white">{{ $movements->appends(['tab'=>'history'])->fragment('stock-history')->links() }}</div>@endif
</div>
@endif
@endsection
