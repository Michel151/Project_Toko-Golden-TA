{{-- Tampilkan isi tab Barang Supplier atau Riwayat PO. --}}
@extends('layouts.app')
@section('title', 'Detail Supplier')
@section('page-title', 'Detail Supplier')

@section('content')
<div class="mb-3">
    <x-back-link :fallback="route('master.suppliers.index')" />
</div>

<div class="inventory-heading d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div class="text-break">
        <h5><i class="bi bi-truck me-2" aria-hidden="true"></i>{{ $supplier->name }}</h5>
        <div class="d-flex flex-wrap gap-2 column-gap-3 small mt-2">
            <span><span class="text-white-50">Telepon:</span> {{ $supplier->phone ?: '—' }}</span>
            <span><span class="text-white-50">Alamat:</span> {{ $supplier->address ?: '—' }}</span>
        </div>
    </div>
    @if(auth()->user()->canAccessRoute('master.suppliers.edit'))
        <a href="{{ route('master.suppliers.edit', $supplier) }}" class="btn btn-warning btn-sm">
            <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Kelola Supplier
        </a>
    @endif
</div>

<nav class="nav nav-pills flex-wrap gap-2 card card-body flex-row p-2 mb-3" aria-label="Bagian Detail Supplier">
    <a class="nav-link @if($activeTab === 'products') active @endif"
       href="{{ route('master.suppliers.show', ['supplier' => $supplier] + $searchParams) }}"
       @if($activeTab === 'products') aria-current="page" @endif>
        <i class="bi bi-box-seam me-1" aria-hidden="true"></i>Barang Supplier
        <span class="badge bg-light text-dark ms-1">{{ number_format($productCount, 0, ',', '.') }}</span>
    </a>
    @if($canViewOrders)
        <a class="nav-link @if($activeTab === 'orders') active @endif"
           href="{{ route('master.suppliers.show', ['supplier' => $supplier, 'tab' => 'orders'] + $searchParams) }}"
           @if($activeTab === 'orders') aria-current="page" @endif>
            <i class="bi bi-clock-history me-1" aria-hidden="true"></i>Riwayat PO
        </a>
    @endif
</nav>

<div class="card table-card">
    @if($activeTab === 'products')
        <form method="GET" action="{{ route('master.suppliers.show', $supplier) }}" class="app-unified-filter app-filter-bar">
            <div class="app-unified-field app-unified-search">
                <label for="supplier-product-search">Cari Barang Supplier</label>
                <input id="supplier-product-search" type="search" name="q" value="{{ $search }}"
                       class="form-control" placeholder="Cari nama barang...">
            </div>
            <div class="app-unified-actions">
                <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Terapkan</button>
                <a class="btn btn-outline-secondary" href="{{ route('master.suppliers.show', $supplier) }}">
                    <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset
                </a>
            </div>
        </form>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" aria-label="Barang Supplier">
                <thead>
                    <tr>
                        <th scope="col">Barang</th>
                        <th scope="col">Satuan Beli</th>
                        <th scope="col" class="text-end">Harga Beli</th>
                    </tr>
                </thead>
                <tbody>
                    {{-- Baca harga beli khusus untuk pasangan supplier dan barang. --}}
                    @forelse($rows as $product)
                        <tr>
                            <td class="fw-semibold text-break">{{ $product->name }}</td>
                            <td>{{ $product->buyUnit?->name ?? '—' }}</td>
                            <td class="text-end text-nowrap">Rp {{ number_format($product->pivot->buy_price, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-muted py-4">{{ $search === '' ? 'Belum ada barang supplier.' : 'Tidak ada barang yang sesuai pencarian.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" aria-label="Riwayat Purchase Order">
                <thead>
                    <tr>
                        <th scope="col">No. PO</th>
                        <th scope="col">Tanggal</th>
                        <th scope="col" class="text-center">Status</th>
                        <th scope="col" class="text-end">Nilai</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $po)
                        <tr>
                            <td><a href="{{ route('purchase.orders.show', $po) }}" class="text-decoration-none">{{ $po->po_number }}</a></td>
                            <td class="text-nowrap">{{ $po->order_date->format('d/m/Y') }}</td>
                            <td class="text-center"><span class="badge bg-{{ \App\Models\PurchaseOrder::STATUS_COLORS[$po->status] ?? 'secondary' }}">{{ $po->status_label }}</span></td>
                            <td class="text-end text-nowrap">Rp {{ number_format($po->totalValue(), 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-4">Belum ada PO untuk supplier ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
    {{-- Tampilkan navigasi untuk tab yang sedang dibuka. --}}
    @if($rows->total() > 0)
        <div class="card-footer bg-white">{{ $rows->links(data: ['itemLabel' => $activeTab === 'products' ? 'barang' : 'PO']) }}</div>
    @endif
</div>
@endsection
