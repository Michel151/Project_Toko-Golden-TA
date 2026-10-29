@extends('layouts.app')
@section('title', $return->return_number)
@section('page-title', 'Detail Retur/Klaim Supplier')
@push('styles')
<style>.supplier-return-table { min-width:850px; } .supplier-return-table th { white-space:nowrap; }</style>
@endpush
@section('content')
@php $order=$return->purchaseReceipt->purchaseOrder; @endphp
<div class="mb-3"><x-back-link :fallback="route('purchase.returns.index')" /></div>
<div class="procurement-heading mb-3 d-flex flex-wrap justify-content-between gap-2"><div><h5 class="fw-bold mb-1">{{ $return->return_number }}</h5><div>{{ $order->supplier->name }} | {{ $order->po_number }}</div></div><strong>{{ ['draft'=>'Menunggu Persetujuan','approved'=>'Disetujui','rejected'=>'Ditolak'][$return->status] }}</strong></div>
<div class="bg-white p-3 mb-3 border-bottom"><div class="row g-3"><div class="col-md-4"><div class="text-muted small">Dibuat Oleh</div>{{ $return->createdBy->name }} - {{ $return->created_at->format('d/m/Y H:i') }}</div><div class="col-md-4"><div class="text-muted small">Persetujuan</div>{{ $return->approvedBy?->name ?? '-' }} {{ $return->approved_at?->format('d/m/Y H:i') }}</div><div class="col-md-4"><div class="text-muted small">Penerimaan Asal</div>@if(auth()->user()->canAccessRoute('purchase.receipts.show'))<a href="{{ route('purchase.receipts.show',$return->purchaseReceipt) }}">Penerimaan #{{ $return->purchase_receipt_id }}</a>@else #{{ $return->purchase_receipt_id }}@endif</div></div>@if($return->notes)<div class="mt-3 border-top pt-2" style="white-space:pre-wrap">{{ $return->notes }}</div>@endif</div>
<section class="bg-white mb-3" data-detail-list>
@include('components.detail-list-tools', ['id'=>'returnItemsSearch'])
<div class="table-responsive app-detail-scroll" tabindex="0" role="region" aria-label="Rincian retur dan klaim"><table class="table align-middle mb-0 supplier-return-table"><thead><tr><th>Barang</th><th>Jenis</th><th class="text-end">Jumlah</th><th>Satuan</th><th>Lokasi Barang Retur</th><th>Keterangan</th></tr></thead><tbody>
@foreach($return->items as $row)@php $inspection=$row->purchaseInspection; $item=$inspection->purchaseOrderItem; @endphp
<tr><td>{{ $item->product->name }}</td><td>{{ \App\Models\PurchaseReturnItem::KINDS[$row->kind] }}</td><td class="text-end">{{ \App\Support\DisplayNumber::quantity($row->quantity) }}</td><td>{{ $item->productUnit?->unit?->name }}</td><td>{{ $row->kind==='missing'?'Tidak ada barang fisik':$inspection->quarantineLocation?->name }}</td><td style="max-width:320px;white-space:normal">{{ $inspection->reason }}</td></tr>
@endforeach
</tbody></table></div></section>
@if($return->status==='draft' && auth()->user()->canAccessRoute('purchase.returns.approve'))
<div class="bg-white p-3 mb-3 border-bottom"><form method="POST" action="{{ route('purchase.returns.approve',$return) }}" data-confirm="Setujui pengajuan retur/klaim dan terbitkan PO Retur tanpa tagihan tambahan?" data-confirm-title="Persetujuan Retur/Klaim" data-confirm-tone="success" data-confirm-label="Setujui dan Terbitkan PO" class="mb-3">@csrf<button class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Setujui dan Terbitkan PO</button></form><form method="POST" action="{{ route('purchase.returns.reject',$return) }}" data-confirm="Tolak pengajuan retur/klaim ini?" data-confirm-label="Tolak Pengajuan" class="d-flex flex-wrap gap-2"><input name="rejection_reason" class="form-control" placeholder="Alasan penolakan" aria-label="Alasan penolakan" maxlength="2000" required>@csrf<button class="btn btn-outline-danger text-nowrap"><i class="bi bi-x-circle me-1"></i>Tolak Pengajuan</button></form></div>
@endif
@if($return->replacementOrder)
<section class="bg-white p-3 mb-3 border-bottom"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h6 class="fw-bold mb-1">PO Retur: {{ $return->replacementOrder->po_number }}</h6><span class="small text-muted">Tanpa tagihan tambahan | {{ $return->replacementOrder->status==='completed'?'Pemenuhan Selesai':'Menunggu Penerimaan' }}</span></div><div class="d-flex gap-2">@if(auth()->user()->canAccessRoute('purchase.orders.show'))<a class="btn btn-sm btn-outline-secondary" href="{{ route('purchase.orders.show',$return->replacementOrder) }}"><i class="bi bi-eye me-1"></i>Detail PO</a>@endif @if($return->replacementOrder->status!=='completed' && auth()->user()->canAccessRoute('purchase.receipts.create'))<a class="btn btn-sm btn-primary" href="{{ route('purchase.receipts.create',['order_id'=>$return->replacementOrder->id]) }}"><i class="bi bi-box-arrow-in-down me-1"></i>Catat Penerimaan</a>@endif</div></div>
<div class="app-detail-scroll">@foreach($return->replacementOrder->items as $replacement)<div class="d-flex flex-wrap justify-content-between border-top py-2 gap-2"><span>{{ $replacement->product->name }}</span><span>{{ \App\Support\DisplayNumber::quantity($replacement->quantity_received) }} / {{ \App\Support\DisplayNumber::quantity($replacement->quantity_ordered) }} {{ $replacement->productUnit?->unit?->name }} baik</span></div>@endforeach</div></section>
@endif
@endsection
