@extends('layouts.app')
@section('title', 'Laporan Stok')
@section('page-title', 'Laporan Stok')

@section('content')
<div class="report-heading d-flex justify-content-between align-items-center mb-3"><div class="d-flex align-items-center gap-3"><div class="report-icon"><i class="bi bi-box-seam"></i></div><div><h5 class="fw-bold mb-1">Laporan Stok</h5><div class="text-white-50 small">Pantau ketersediaan dan nilai persediaan seluruh lokasi</div></div></div><span class="badge bg-warning text-dark">{{ $products->count() }} Barang</span></div>
<div class="report-toolbar d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
    <form class="d-flex flex-wrap gap-2 align-items-end" method="GET">
        <div><label class="d-block">Kategori</label><select name="category_id" class="form-select form-select-sm" style="width:170px">
            <option value="">Semua Kategori</option>
            @foreach($categories as $cat)
            <option value="{{ $cat->id }}" {{ request('category_id') == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
            @endforeach
        </select></div>
        <div><label class="d-block">Lokasi</label><select name="location_id" class="form-select form-select-sm" style="width:150px">
            <option value="">Semua Lokasi</option>
            @foreach($locations as $loc)
            <option value="{{ $loc->id }}" {{ request('location_id') == $loc->id ? 'selected' : '' }}>{{ $loc->name }}</option>
            @endforeach
        </select></div>
        <div><label class="d-block">Kedaluwarsa</label><select name="expiry_status" class="form-select form-select-sm" style="width:210px">
            <option value="">Semua Status</option>
            @foreach($expiryFilters as $value => $label)
            <option value="{{ $value }}" {{ request('expiry_status') === $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select></div>
        <button class="btn btn-sm btn-primary px-3"><i class="bi bi-funnel me-1"></i>Terapkan</button>
        @if(request()->hasAny(['category_id','location_id','expiry_status']))<a href="{{ route('report.stock') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i></a>@endif
    </form>
    <div class="btn-group btn-group-sm">
        <a href="{{ route('report.stock.pdf', request()->all()) }}" class="btn btn-outline-danger" target="_blank"><i class="bi bi-file-pdf me-1"></i>PDF</a>
        <a href="{{ route('report.stock.excel', request()->all()) }}" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    </div>
</div>
@include('components.report-kpis',['metrics'=>[
    ['label'=>'Jenis Barang','value'=>number_format($stockSummary['products'],0,',','.'),'hint'=>'Barang sesuai filter','icon'=>'box-seam','tone'=>'primary'],
    ['label'=>'Nilai Persediaan','value'=>'Rp '.number_format($stockSummary['value'],0,',','.'),'hint'=>'Stok fisik seluruh lokasi - harga beli','icon'=>'cash-stack','tone'=>'success'],
    ['label'=>'Stok Rendah','value'=>number_format($stockSummary['low'],0,',','.').' jenis','hint'=>'Tersedia, tidak melebihi minimum','icon'=>'exclamation-triangle','tone'=>'warning'],
    ['label'=>'Stok Layak Jual Habis','value'=>number_format($stockSummary['empty'],0,',','.').' jenis','hint'=>'Tidak ada stok yang layak dijual','icon'=>'box','tone'=>'danger'],
]])
@if($expiryFilterLabel)
<div class="alert alert-warning py-2 mb-3">
    <i class="bi bi-calendar-x me-1"></i>Laporan difilter berdasarkan kedaluwarsa: <strong>{{ $expiryFilterLabel }}</strong>. Export PDF/Excel akan dibuat sebagai laporan kedaluwarsa barang.
</div>
@endif

<div class="card table-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover report-table mb-0">
                <thead>
                    <tr>
                        <th>Barang</th>
                        <th>Kategori</th>
                        @foreach($locations as $loc)
                        <th class="text-end">{{ $loc->name }}</th>
                        @endforeach
                        <th class="text-end">Total Fisik</th>
                        <th class="text-end">Layak Jual</th>
                        <th class="text-end">Kedaluwarsa</th>
                        <th class="text-end">Min. Total</th>
                        <th class="text-end">Nilai Stok</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $grandValue = 0;
                        $fmtRpt = static fn ($qty, $product) => $product->displayStock((float) $qty);
                    @endphp
                    @forelse($products as $product)
                    @php
                        $total = (float) $product->stocks->sum('quantity');
                        $sellable = $product->is_expirable
                            ? (float) $product->stockBatches
                                ->filter(fn ($batch) => (float) $batch->quantity_remaining > 0 && (! $batch->expired_date || $batch->expired_date->gte(today())))
                                ->sum(fn ($batch) => (float) $batch->quantity_remaining)
                            : $total;
                        $expired = $product->is_expirable
                            ? (float) $product->stockBatches
                                ->filter(fn ($batch) => (float) $batch->quantity_remaining > 0 && $batch->expired_date && $batch->expired_date->lt(today()))
                                ->sum(fn ($batch) => (float) $batch->quantity_remaining)
                            : 0;
                        $minTot = (float) $product->min_stock_total;
                        $isLow = $sellable <= $minTot;

                        $purchaseUnit = $product->productUnits->firstWhere('is_default_purchase', true);
                        $baseCost = $purchaseUnit && (float) $purchaseUnit->conversion_to_base > 0
                            ? (float) $purchaseUnit->buy_price / (float) $purchaseUnit->conversion_to_base
                            : (float) $product->buy_price;
                        $value = $total * $baseCost;
                        $grandValue += $value;

                    @endphp
                    <tr>
                        <td class="fw-semibold">{{ $product->name }}</td>
                        <td class="text-muted small">{{ $product->category->name ?? '-' }}</td>
                        @foreach($locations as $loc)
                        @php
                            $qty = $product->is_expirable
                                ? (float) $product->stockBatches
                                    ->where('location_id', $loc->id)
                                    ->filter(fn ($batch) => (float) $batch->quantity_remaining > 0 && (! $batch->expired_date || $batch->expired_date->gte(today())))
                                    ->sum(fn ($batch) => (float) $batch->quantity_remaining)
                                : (float)($product->stocks->where('location_id', $loc->id)->first()?->quantity ?? 0);
                        @endphp
                        <td class="text-end small">{{ $fmtRpt($qty, $product) }}</td>
                        @endforeach
                        <td class="text-end fw-bold {{ $isLow ? 'text-danger' : '' }}">
                            {{ $fmtRpt($total, $product) }}
                        </td>
                        <td class="text-end fw-semibold {{ $isLow ? 'text-danger' : 'text-success' }}">{{ $fmtRpt($sellable, $product) }}</td>
                        <td class="text-end small {{ $expired > 0 ? 'text-danger fw-semibold' : 'text-muted' }}">{{ $fmtRpt($expired, $product) }}</td>
                        <td class="text-end text-muted small">{{ $fmtRpt($minTot, $product) }}</td>
                        <td class="text-end small">Rp {{ number_format($value, 0, ',', '.') }}</td>
                        <td class="text-center">
                            <span class="badge bg-{{ $isLow ? 'danger' : 'success' }}">
                                {{ $isLow ? 'Rendah' : 'Aman' }}
                            </span>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="{{ 7 + $locations->count() }}" class="report-empty"><i class="bi bi-inbox fs-2 d-block mb-2"></i>Tidak ada data stok</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="{{ 6 + $locations->count() }}">TOTAL NILAI STOK</td>
                        <td class="text-end" colspan="2">Rp {{ number_format($grandValue, 0, ',', '.') }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
@endsection
