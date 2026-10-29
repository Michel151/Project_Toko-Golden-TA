@extends('layouts.app')
@section('title', 'Riwayat Supplier')
@section('page-title', 'Riwayat Supplier')

@section('content')
<div class="inventory-heading"><h5><i class="bi bi-truck me-2"></i>{{ $supplier->name }}</h5></div>
<div class="mb-3">
    <x-back-link :fallback="route('master.suppliers.index')" />
</div>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card table-card">
            <div class="card-body">
                <h6 class="fw-bold mb-3"><i class="bi bi-truck me-2"></i>Info Supplier</h6>
                <div class="mb-2"><span class="text-muted small">Nama:</span><br>
                    <strong>{{ $supplier->name }}</strong></div>
                <div class="mb-2"><span class="text-muted small">Telepon:</span><br>
                    {{ $supplier->phone ?? '-' }}</div>
                <div><span class="text-muted small">Alamat:</span><br>
                    {{ $supplier->address ?? '-' }}</div>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card table-card mb-3">
            <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0">Barang Supplier</h6>
                @if(auth()->user()->canAccessRoute('master.suppliers.edit')) <a href="{{ route('master.suppliers.edit', $supplier) }}" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-pencil-square me-1"></i> Kelola
                </a> @endif
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Barang</th>
                            <th>Satuan Beli</th>
                            <th class="text-end">Harga Beli</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($supplier->products as $product)
                        <tr>
                            <td>{{ $product->name }}</td>
                            <td>{{ $product->buyUnit->name }}</td>
                            <td class="text-end">Rp {{ number_format($product->pivot->buy_price, 0, ',', '.') }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="3" class="text-center text-muted py-3">Belum ada barang supplier.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if(auth()->user()->hasPermission('purchase.orders.view'))
        <div class="card table-card">
            <div class="card-header bg-white border-0 pt-3">
                <h6 class="fw-bold mb-0">Riwayat Purchase Order</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>No. PO</th>
                            <th>Tanggal</th>
                            <th class="text-center">Status</th>
                            <th class="text-end">Nilai</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($purchaseOrders as $po)
                        <tr>
                            <td>
                                <a href="{{ route('purchase.orders.show', $po) }}" class="text-decoration-none">
                                    {{ $po->po_number }}
                                </a>
                            </td>
                            <td>{{ $po->order_date->format('d/m/Y') }}</td>
                            <td class="text-center">
                                @php
                                    $colors = \App\Models\PurchaseOrder::STATUS_COLORS;
                                @endphp
                                <span class="badge bg-{{ $colors[$po->status] ?? 'secondary' }}">
                                    {{ $po->status_label }}
                                </span>
                            </td>
                            <td class="text-end">Rp {{ number_format($po->totalValue(), 0, ',', '.') }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">Belum ada PO</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($purchaseOrders->hasPages())
            <div class="card-footer bg-white">{{ $purchaseOrders->links() }}</div>
            @endif
        </div>
        @endif
    </div>
</div>
@endsection
