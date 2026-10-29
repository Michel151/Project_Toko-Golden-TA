@extends('layouts.app')
@section('title', 'Laporan Pembelian')
@section('page-title', 'Laporan Pembelian')

@section('content')
<div class="report-heading d-flex justify-content-between align-items-center mb-3"><div class="d-flex align-items-center gap-3"><div class="report-icon"><i class="bi bi-cart-check"></i></div><div><h5 class="fw-bold mb-1">Laporan Pembelian</h5><div class="text-white-50 small">Pantau nilai dan progres seluruh purchase order</div></div></div><span class="badge bg-warning text-dark">{{ $orderCount }} PO</span></div>
<div class="report-toolbar d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
    <form class="app-unified-filter d-flex gap-2 align-items-end" method="GET">
        @include('components.date-range',['prefix'=>'purchaseReport','fromValue'=>$from,'toValue'=>$to])
        <div><label for="purchaseReportSupplier">Supplier</label><select id="purchaseReportSupplier" name="supplier_id" class="form-select form-select-sm" style="max-width:260px">
            <option value="">Semua Supplier</option>
            @foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected((string) request('supplier_id') === (string) $supplier->id)>{{ $supplier->name }}</option>@endforeach
        </select></div>
        <div><label for="purchasePageSize">Per Halaman</label><select id="purchasePageSize" name="per_page" class="form-select form-select-sm">@foreach([25,50,100] as $size)<option value="{{ $size }}" @selected((int)request('per_page',25)===$size)>{{ $size }}</option>@endforeach</select></div>
        <button class="btn btn-sm btn-primary px-3"><i class="bi bi-funnel me-1"></i>Terapkan</button>
    <a href="{{ route('report.purchase') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset</a></form>
    <div class="btn-group btn-group-sm">
        <a href="{{ route('report.purchase.pdf', request()->all()) }}" class="btn btn-outline-danger" target="_blank"><i class="bi bi-file-pdf me-1"></i>PDF</a>
        <a href="{{ route('report.purchase.excel', request()->all()) }}" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-sm-4">
        <div class="card report-stat h-100" style="--stat-color:#c8960c"><div class="card-body d-flex align-items-center gap-3"><div class="stat-symbol"><i class="bi bi-wallet2"></i></div><div>
            <div class="text-muted small">Total Nilai PO</div>
            <div class="fw-bold">Rp {{ number_format($totalValue, 0, ',', '.') }}</div>
        </div></div></div>
    </div>
    <div class="col-12 col-sm-4">
        <div class="card report-stat h-100" style="--stat-color:#198754"><div class="card-body d-flex align-items-center gap-3"><div class="stat-symbol"><i class="bi bi-check-circle"></i></div><div>
            <div class="text-muted small">PO Selesai</div>
            <div class="fw-bold text-success">{{ $completed }}</div>
        </div></div></div>
    </div>
    <div class="col-12 col-sm-4">
        <div class="card report-stat h-100" style="--stat-color:#fd7e14"><div class="card-body d-flex align-items-center gap-3"><div class="stat-symbol"><i class="bi bi-hourglass-split"></i></div><div>
            <div class="text-muted small">PO Belum Selesai</div>
            <div class="fw-bold text-warning">{{ $pending }}</div>
        </div></div></div>
    </div>
</div>

<div class="card table-card">
    <div class="card-body p-0 table-responsive app-table-scroll" tabindex="0" role="region" aria-label="Daftar purchase order dalam laporan">
        <table class="table table-hover report-table mb-0">
            <thead><tr><th>No. PO</th><th>Supplier</th><th>Tanggal</th><th class="text-center">Status</th><th class="text-end">Nilai PO</th></tr></thead>
            <tbody>
                @forelse($orders as $po)
                <tr>
                    <td><a href="{{ route('purchase.orders.show', $po) }}" class="text-decoration-none">{{ $po->po_number }}</a></td>
                    <td>{{ $po->supplier->name }}</td>
                    <td>{{ $po->order_date->format('d/m/Y') }}</td>
                    <td class="text-center">
                        @php $colors = \App\Models\PurchaseOrder::STATUS_COLORS @endphp
                        <span class="badge bg-{{ $colors[$po->status] }}">{{ $po->status_label }}</span>
                    </td>
                    <td class="text-end">Rp {{ number_format($po->totalValue(), 0, ',', '.') }}</td>
                </tr>
                @empty
                <tr><td colspan="5" class="report-empty"><i class="bi bi-inbox fs-2 d-block mb-2"></i>Tidak ada purchase order pada periode ini</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white">{{ $orders->links() }}</div>
</div>
@endsection
