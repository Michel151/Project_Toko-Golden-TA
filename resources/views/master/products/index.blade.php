@extends('layouts.app')
@section('title', 'Barang')
@section('page-title', 'Master Barang')
@push('styles')
<style>
.product-list-toolbar { justify-content:space-between; }
.product-list-filters { display:flex;flex-wrap:wrap;align-items:end;gap:12px;flex-grow:1; }
@media(max-width:767px) {
    .app-filter-bar.product-list-toolbar { display:flex;align-items:stretch; }
    .product-list-filters { display:grid;grid-template-columns:repeat(2,minmax(0,1fr));width:100%; }
    .product-list-filters .app-filter-search,.product-list-filters .app-filter-actions { grid-column:1/-1; }
}
</style>
@endpush

@section('content')
@include('master._header', ['icon'=>'box-seam', 'title'=>'Data Barang', 'description'=>'Kelola identitas, harga, satuan, barcode, dan persediaan barang.'])
<div class="app-filter-bar product-list-toolbar mb-3">
    <form class="product-list-filters" method="GET">
        <div class="app-filter-field app-filter-search"><label for="productSearch">Cari Barang</label><input id="productSearch" type="search" name="search" class="form-control form-control-sm"
               placeholder="Nama / barcode..." value="{{ request('search') }}"></div>
        <div class="app-filter-field"><label for="productCategory">Kategori</label><select id="productCategory" name="category_id" class="form-select form-select-sm">
            <option value="">Semua Kategori</option>
            @foreach($categories as $cat)
            <option value="{{ $cat->id }}" {{ request('category_id') == $cat->id ? 'selected' : '' }}>
                {{ $cat->name }}
            </option>
            @endforeach
        </select></div>
        <div class="app-filter-field"><label for="productStatus">Status</label><select id="productStatus" name="status" class="form-select form-select-sm">
            <option value="">Semua Status</option>
            <option value="active" @selected(request('status') === 'active')>Aktif</option>
            <option value="inactive" @selected(request('status') === 'inactive')>Nonaktif</option>
        </select></div>
        <div class="app-filter-actions"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Terapkan</button>
        @if(request()->hasAny(['search','category_id','status']))
        <a href="{{ route('master.products.index') }}" class="btn btn-sm btn-outline-secondary" title="Reset filter" aria-label="Reset filter">
            <i class="bi bi-arrow-counterclockwise"></i>
        </a>
        @endif
        </div>
    </form>
    <div class="d-flex gap-2 flex-wrap">
    @if(auth()->user()->canAccessRoute('master.products.import')) <a href="{{ route('master.products.import') }}" class="btn btn-outline-success btn-sm">
        <i class="bi bi-file-earmark-excel me-1"></i> Import Excel
    </a> @endif
    @if(auth()->user()->canAccessRoute('master.products.create')) <a href="{{ route('master.products.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-circle me-1"></i> Tambah Barang
    </a> @endif
    </div>
</div>

<div class="card table-card">
    <div class="card-body p-0 table-responsive">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th width="50">#</th>
                        <th>Nama Barang</th>
                        <th>Kategori</th>
                        <th>Status</th>
                        <th>Satuan</th>
                        <th class="text-end">Harga Beli</th>
                        <th class="text-end">Harga Jual</th>
                        <th class="text-center">Jumlah Satuan</th>
                        <th class="text-end">Total Stok</th>
                        <th width="120" class="text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($products as $product)
                    @php
                        $totalStock = $product->totalStock();
                        $isLow      = $product->isBelowMinStock();

                        $stokDisplay = $product->displayStock($totalStock);
                        $purchaseUnit = $product->productUnits->firstWhere('is_default_purchase', true);
                        $saleUnit = $product->productUnits->firstWhere('is_default_sale', true);
                    @endphp
                    <tr class="{{ $isLow ? 'table-warning' : '' }}">
                        <td class="text-muted">{{ $products->firstItem() + $loop->index }}</td>
                        <td>
                            <div class="fw-semibold">{{ $product->name }}</div>
                            <div class="small text-muted font-monospace"><i class="bi bi-upc-scan me-1"></i>{{ $product->barcode ?? '-' }}</div>
                            @if($isLow)
                            <span class="badge bg-danger" style="font-size:0.65rem">Stok Rendah</span>
                            @endif
                        </td>
                        <td class="text-muted small">{{ $product->category->name ?? '-' }}</td>
                        <td>
                            <span class="badge bg-{{ $product->is_active ? 'success' : 'secondary' }}">
                                {{ $product->is_active ? 'Aktif' : 'Nonaktif' }}
                            </span>
                        </td>
                        <td class="text-muted small">{{ $product->productUnits->pluck('unit.name')->filter()->implode(' / ') ?: ($product->buyUnit->name ?? '-') }}</td>
                        <td class="text-end small">Rp {{ number_format($purchaseUnit->buy_price ?? $product->buy_price, 0, ',', '.') }}</td>
                        <td class="text-end small">Rp {{ number_format($saleUnit->sell_price ?? $product->sell_price_wholesale, 0, ',', '.') }}</td>
                        <td class="text-center">
                            <span class="badge bg-primary bg-opacity-10 text-primary">{{ $product->productUnits->count() }}</span>
                        </td>
                        <td class="text-end fw-semibold {{ $isLow ? 'text-danger' : '' }}">
                            {{ $stokDisplay }}
                        </td>
                        <td class="text-center">
                            <a href="{{ route('master.products.show', $product) }}"
                               class="btn btn-sm btn-outline-info py-0 px-2" title="Detail">
                                <i class="bi bi-eye"></i>
                            </a>
                            @if(auth()->user()->canAccessRoute('master.products.edit')) <a href="{{ route('master.products.edit', $product) }}"
                               class="btn btn-sm btn-outline-warning py-0 px-2">
                                <i class="bi bi-pencil"></i>
                            </a> @endif
                            @if(auth()->user()->canAccessRoute('master.products.destroy')) <form method="POST" action="{{ route('master.products.destroy', $product) }}"
                                  class="d-inline" data-confirm="Hapus barang ini?">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger py-0 px-2">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form> @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="10" class="text-center text-muted py-4">Belum ada barang</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($products->hasPages())
    <div class="card-footer bg-white">{{ $products->links() }}</div>
    @endif
</div>
@endsection
