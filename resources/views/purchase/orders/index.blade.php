@extends('layouts.app')
@section('title','Purchase Order')
@section('page-title','Purchase Order')

@section('content')
<div class="procurement-heading d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3"><div class="d-flex align-items-center gap-3"><div class="heading-icon"><i class="bi bi-cart-check"></i></div><div><h5 class="fw-bold mb-1">Purchase Order</h5><div class="small text-white-50">Kelola pemesanan dan pantau progres penerimaan barang</div></div></div>@if(auth()->user()->canAccessRoute('purchase.orders.create')) <a href="{{ route('purchase.orders.create') }}" class="btn btn-primary btn-sm px-3"><i class="bi bi-plus-circle me-1"></i>Buat PO Baru</a> @endif</div>

<div class="row g-3 mb-3">
    <div class="col-12 col-md-6 col-xxl-4"><div class="card procurement-stat h-100" style="--accent:#c8960c"><div class="card-body"><div class="text-muted small">Total Nilai PO Terkonfirmasi</div><div class="fw-bold fs-5">Rp {{ number_format($totalOrderValue,0,',','.') }}</div><div class="small text-muted">Tidak termasuk draf dan PO dibatalkan</div></div></div></div>
    <div class="col-6 col-md-3 col-xxl"><div class="card procurement-stat h-100" style="--accent:#6c757d"><div class="card-body"><div class="text-muted small">Draf</div><div class="fw-bold fs-5">{{ $draftCount }}</div></div></div></div>
    <div class="col-6 col-md-3 col-xxl"><div class="card procurement-stat h-100" style="--accent:#fd7e14"><div class="card-body"><div class="text-muted small">Menunggu</div><div class="fw-bold fs-5 text-warning">{{ $pendingCount }}</div></div></div></div>
    <div class="col-6 col-md-4 col-xxl"><div class="card procurement-stat h-100" style="--accent:#0dcaf0"><div class="card-body"><div class="text-muted small">Diterima Sebagian</div><div class="fw-bold fs-5 text-info">{{ $partialCount }}</div></div></div></div>
    <div class="col-6 col-md-4 col-xxl"><div class="card procurement-stat h-100" style="--accent:#198754"><div class="card-body"><div class="text-muted small">Selesai</div><div class="fw-bold fs-5 text-success">{{ $completedCount }}</div></div></div></div>
    <div class="col-6 col-md-4 col-xxl"><div class="card procurement-stat h-100" style="--accent:#dc3545"><div class="card-body"><div class="text-muted small">Dibatalkan</div><div class="fw-bold fs-5">{{ $cancelledCount }}</div></div></div></div>
</div>

<div class="procurement-filter mb-3"><form class="app-unified-filter d-flex flex-wrap gap-2 align-items-end" method="GET">
    <div><label>Supplier</label><select name="supplier_id" class="form-select form-select-sm" style="width:180px"><option value="">Semua Supplier</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected(request('supplier_id')==$supplier->id)>{{ $supplier->name }}</option>@endforeach</select></div>
    <div><label>Status</label><select name="status" class="form-select form-select-sm" style="width:190px"><option value="">Semua Status</option>@foreach(\App\Models\PurchaseOrder::STATUS_LABELS as $status=>$label)<option value="{{ $status }}" @selected(request('status')===$status)>{{ $label }}</option>@endforeach</select></div>
    <div><label>Dari Tanggal</label><input type="date" name="from" class="form-control form-control-sm" value="{{ $from }}"></div><div><label>Sampai Tanggal</label><input type="date" name="to" class="form-control form-control-sm" value="{{ $to }}"></div>
    <button class="btn btn-sm btn-primary px-3"><i class="bi bi-funnel me-1"></i>Terapkan</button><a href="{{ route('purchase.orders.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset</a>
</form></div>

<div class="card table-card"><div class="card-header bg-white border-0 py-3 d-flex justify-content-between"><div><h6 class="fw-bold mb-1">Daftar Purchase Order</h6><small class="text-muted">{{ $orders->total() }} dokumen ditemukan</small></div></div><div class="table-responsive"><table class="table table-hover procurement-table mb-0"><thead><tr><th>No. PO</th><th>Supplier</th><th>Tanggal</th><th>Progres</th><th class="text-center">Status</th><th class="text-end">Nilai PO</th><th>Dibuat Oleh</th><th class="text-center">Aksi</th></tr></thead><tbody>
@forelse($orders as $order)
@php $progress=$order->items->count()?round($order->items->avg(fn($item)=>(float)$item->quantity_ordered>0?min(100,((float)$item->quantity_received/(float)$item->quantity_ordered)*100):0)):0;$colors=\App\Models\PurchaseOrder::STATUS_COLORS; @endphp
<tr>
    <td><a href="{{ route('purchase.orders.show',$order) }}" class="font-monospace fw-semibold text-decoration-none">{{ $order->po_number }}</a></td>
    <td><div class="fw-semibold">{{ $order->supplier->name }}</div></td>
    <td>{{ $order->order_date?->format('d/m/Y')??'-' }}</td>
    <td style="min-width:130px"><div class="d-flex justify-content-between small mb-1"><span>{{ $order->items->count() }} item</span><span>{{ $progress }}%</span></div><div class="progress" style="height:6px"><div class="progress-bar bg-{{ $colors[$order->status] }}" style="width:{{ $progress }}%"></div></div></td>
    <td class="text-center"><span class="badge bg-{{ $colors[$order->status] }}">{{ $order->status_label }}</span></td>
    <td class="text-end fw-semibold">Rp {{ number_format($order->totalValue(),0,',','.') }}</td>
    <td class="small text-muted">{{ $order->createdBy->name??'-' }}</td>
    <td class="text-center text-nowrap">
        <a href="{{ route('purchase.orders.show',$order) }}" class="btn btn-sm btn-outline-primary py-0 px-2" title="Detail"><i class="bi bi-eye"></i></a>
            <a href="{{ route('purchase.orders.pdf',$order) }}" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" title="Unduh PDF"><i class="bi bi-file-pdf" aria-hidden="true"></i> PDF</a>
            <a href="{{ route('purchase.orders.excel',$order) }}" class="btn btn-sm btn-outline-secondary" title="Unduh Excel"><i class="bi bi-file-earmark-excel" aria-hidden="true"></i> Excel</a>
        @if($order->canModify() && auth()->user()->canAccessRoute('purchase.orders.edit'))
        <a href="{{ route('purchase.orders.edit',$order) }}" class="btn btn-sm btn-outline-warning py-0 px-2" title="Ubah Draf"><i class="bi bi-pencil"></i></a>
        @endif
        @if($order->canModify() && auth()->user()->canAccessRoute('purchase.orders.confirm'))
        <a href="{{ route('purchase.orders.show',$order) }}#poLifecycle" class="btn btn-sm btn-outline-primary py-0 px-2" title="Tinjau dan Konfirmasi PO"><i class="bi bi-check-circle"></i></a>
        @endif
        @if(($order->canCancel() && auth()->user()->canAccessRoute('purchase.orders.cancel') && ($order->status==='draft' || auth()->user()->role==='owner')) || ($order->canCloseRemainder() && auth()->user()->role==='owner'))
        <a href="{{ route('purchase.orders.show',$order) }}#poLifecycle" class="btn btn-sm btn-outline-danger py-0 px-2" title="{{ $order->status==='partial' ? 'Tutup Sisa Pesanan' : 'Batalkan PO' }}"><i class="bi bi-x-circle"></i></a>
        @endif
        @if(in_array($order->status,['pending','partial']) && $order->items->contains(fn($item) => $item->receivableQuantity() > 0) && auth()->user()->canAccessRoute('purchase.receipts.create'))
        <a href="{{ route('purchase.receipts.create',['order_id'=>$order->id]) }}" class="btn btn-sm btn-outline-success py-0 px-2" title="Terima Barang"><i class="bi bi-box-arrow-in-down"></i></a>
        @endif
    </td>
</tr>
@empty<tr><td colspan="8" class="text-center text-muted py-5"><i class="bi bi-cart-x fs-2 d-block mb-2"></i>Belum ada purchase order</td></tr>@endforelse
</tbody></table></div>@if($orders->hasPages())<div class="card-footer bg-white">{{ $orders->links() }}</div>@endif</div>
@endsection
