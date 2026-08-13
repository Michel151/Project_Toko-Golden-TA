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
        <button class="btn btn-sm btn-primary px-3"><i class="bi bi-funnel me-1"></i>Terapkan</button>
        @if(request()->hasAny(['category_id','location_id']))<a href="{{ route('report.stock') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i></a>@endif
    </form>
    <div class="btn-group btn-group-sm">
        <a href="{{ route('report.stock.pdf', request()->all()) }}" class="btn btn-outline-danger" target="_blank"><i class="bi bi-file-pdf me-1"></i>PDF</a>
        <a href="{{ route('report.stock.excel', request()->all()) }}" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    </div>
</div>

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
                        <th class="text-end">Total Stok</th>
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
                        $minTot = (float) $product->min_stock_total;
                        $isLow = $total <= $minTot;

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
                            $qty = (float)($product->stocks->where('location_id', $loc->id)->first()?->quantity ?? 0);
                        @endphp
                        <td class="text-end small">{{ $fmtRpt($qty, $product) }}</td>
                        @endforeach
                        <td class="text-end fw-bold {{ $isLow ? 'text-danger' : '' }}">
                            {{ $fmtRpt($total, $product) }}
                        </td>
                        <td class="text-end text-muted small">{{ $fmtRpt($minTot, $product) }}</td>
                        <td class="text-end small">Rp {{ number_format($value, 0, ',', '.') }}</td>
                        <td class="text-center">
                            <span class="badge bg-{{ $isLow ? 'danger' : 'success' }}">
                                {{ $isLow ? 'Rendah' : 'Aman' }}
                            </span>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="{{ 5 + $locations->count() }}" class="report-empty"><i class="bi bi-inbox fs-2 d-block mb-2"></i>Tidak ada data stok</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="{{ 4 + $locations->count() }}">TOTAL NILAI STOK</td>
                        <td class="text-end" colspan="2">Rp {{ number_format($grandValue, 0, ',', '.') }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
@endsection
