@extends('layouts.app')
@section('title', 'Barang')
@section('page-title', 'Master Barang')

@section('content')
@include('master._header', ['icon'=>'box-seam', 'title'=>'Data Barang', 'description'=>'Kelola identitas, harga, satuan, barcode, dan persediaan barang.'])
<div class="d-flex justify-content-between align-items-center mb-3">
    <form class="d-flex gap-2" method="GET">
        <input type="text" name="search" class="form-control form-control-sm" style="width:200px"
               placeholder="Nama / barcode..." value="{{ request('search') }}">
        <select name="category_id" class="form-select form-select-sm" style="width:160px">
            <option value="">Semua Kategori</option>
            @foreach($categories as $cat)
            <option value="{{ $cat->id }}" {{ request('category_id') == $cat->id ? 'selected' : '' }}>
                {{ $cat->name }}
            </option>
            @endforeach
        </select>
        <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
        @if(request()->hasAny(['search','category_id']))
        <a href="{{ route('master.products.index') }}" class="btn btn-sm btn-outline-danger">
            <i class="bi bi-x"></i>
        </a>
        @endif
    </form>
    <a href="{{ route('master.products.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-circle me-1"></i> Tambah Barang
    </a>
</div>

<div class="card table-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th width="50">#</th>
                        <th>Nama Barang</th>
                        <th>Kategori</th>
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
                            <a href="{{ route('master.products.edit', $product) }}"
                               class="btn btn-sm btn-outline-warning py-0 px-2">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" action="{{ route('master.products.destroy', $product) }}"
                                  class="d-inline" onsubmit="return confirm('Hapus barang ini?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger py-0 px-2">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">Belum ada barang</td></tr>
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
