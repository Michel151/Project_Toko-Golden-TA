@extends('layouts.app')
@section('title', 'Retur dan Klaim Supplier')
@section('page-title', 'Retur dan Klaim Supplier')
@push('styles')
<style>.supplier-return-table { min-width:850px; } .supplier-return-table th { white-space:nowrap; }</style>
@endpush
@section('content')
<div class="procurement-heading mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2"><h5 class="fw-bold mb-0">Retur dan Klaim Supplier</h5>@if(auth()->user()->canAccessRoute('purchase.returns.create'))<a href="{{ route('purchase.returns.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Buat Retur/Klaim</a>@endif</div>
<form method="GET" class="app-filter-bar mb-3"><div class="app-filter-field"><label for="returnStatus">Status</label><select id="returnStatus" name="status" class="form-select form-select-sm"><option value="">Semua Status</option>@foreach(['draft'=>'Menunggu Persetujuan','approved'=>'Disetujui','rejected'=>'Ditolak'] as $key=>$label)<option value="{{ $key }}" @selected(request('status')===$key)>{{ $label }}</option>@endforeach</select></div><div class="app-filter-actions"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Terapkan</button>@if(request()->filled('status'))<a href="{{ route('purchase.returns.index') }}" class="btn btn-sm btn-outline-secondary" title="Reset filter" aria-label="Reset filter"><i class="bi bi-arrow-counterclockwise"></i></a>@endif</div></form>
<div class="table-responsive bg-white"><table class="table align-middle mb-0 supplier-return-table"><thead><tr><th>Nomor Dokumen / Tanggal</th><th>PO Asal / Supplier</th><th>Status</th><th>PO Retur</th><th></th></tr></thead><tbody>
@forelse($returns as $return)
<tr><td class="text-nowrap"><a href="{{ route('purchase.returns.show',$return) }}">{{ $return->return_number }}</a><div class="small text-muted">{{ $return->created_at->format('d/m/Y H:i') }}</div></td><td>{{ $return->purchaseReceipt->purchaseOrder->po_number }}<div class="small text-muted">{{ $return->purchaseReceipt->purchaseOrder->supplier->name }}</div></td><td>{{ ['draft'=>'Menunggu Persetujuan','approved'=>'Disetujui','rejected'=>'Ditolak'][$return->status] }}</td><td>{{ $return->replacementOrder?->po_number ?? '-' }}@if($return->replacementOrder)<div class="small {{ $return->replacementOrder->status==='completed'?'text-success':'text-muted' }}">{{ $return->replacementOrder->status==='completed'?'Pemenuhan Selesai':'Menunggu Penerimaan' }}</div>@endif</td><td><a class="btn btn-sm btn-outline-secondary" title="Detail retur/klaim" href="{{ route('purchase.returns.show',$return) }}"><i class="bi bi-eye"></i></a></td></tr>
@empty<tr><td colspan="5" class="text-center text-muted p-4">Belum ada retur atau klaim supplier.</td></tr>@endforelse
</tbody></table></div><div class="mt-3">{{ $returns->links() }}</div>
@endsection
