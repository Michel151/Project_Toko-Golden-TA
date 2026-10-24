@extends('layouts.app')
@section('title', 'Buat Retur/Klaim Supplier')
@section('page-title', 'Buat Retur/Klaim Supplier')
@push('styles')
<style>.supplier-return-table { min-width:850px; } .supplier-return-table th { white-space:nowrap; }</style>
@endpush
@section('content')
<h5 class="fw-bold mb-3">Buat Retur/Klaim Supplier</h5>
<form method="GET" class="bg-white p-3 mb-3 d-flex flex-wrap gap-2 align-items-end"><div class="flex-grow-1"><label for="receiptSelect" class="form-label">Referensi Penerimaan</label><select class="form-select" id="receiptSelect" name="receipt_id" required><option value="">Pilih penerimaan</option>@foreach($receipts as $candidate)<option value="{{ $candidate->id }}" @selected($receipt?->id===$candidate->id)>#{{ $candidate->id }} | {{ $candidate->receipt_date->format('d/m/Y') }} | {{ $candidate->purchaseOrder->po_number }} | {{ $candidate->purchaseOrder->supplier->name }}</option>@endforeach</select></div><button class="btn btn-outline-primary"><i class="bi bi-search me-1"></i>Tampilkan</button></form>
@if($receipt)
<form method="POST" action="{{ route('purchase.returns.store') }}">@csrf<input type="hidden" name="purchase_receipt_id" value="{{ $receipt->id }}">
<input type="hidden" name="submission_token" value="{{ old('submission_token', (string) Str::uuid()) }}">
<div class="bg-white mb-3"><div class="p-3 border-bottom"><strong>{{ $receipt->purchaseOrder->supplier->name }}</strong> <span class="text-muted ms-2">{{ $receipt->purchaseOrder->po_number }}</span></div><div class="table-responsive"><table class="table align-middle mb-0 supplier-return-table"><thead><tr><th>Barang</th><th>Masalah</th><th>Lokasi Barang Retur</th><th>Keterangan Pemeriksaan</th><th class="text-end">Sisa Pengajuan</th><th>Jumlah Diajukan</th></tr></thead><tbody>
@php $rowIndex=0; @endphp
@foreach($receipt->inspections as $inspection)
@foreach(\App\Models\PurchaseReturnItem::KINDS as $kind=>$label)
@php $max=$inspection->claimable($kind); @endphp
@if($max>0)
<tr><td>{{ $inspection->purchaseOrderItem->product->name }}<div class="small text-muted">{{ $inspection->purchaseOrderItem->productUnit?->unit?->name }}</div></td><td>{{ $label }}<div class="small text-muted">{{ $kind==='missing'?'Klaim kekurangan':'Retur fisik' }}</div></td><td>{{ $kind==='missing'?'-':$inspection->quarantineLocation?->name }}</td><td style="min-width:180px;max-width:320px;white-space:normal">{{ $inspection->reason }}</td><td class="text-end">{{ \App\Support\DisplayNumber::quantity($max) }}</td><td style="min-width:130px"><input type="hidden" name="items[{{ $rowIndex }}][purchase_inspection_id]" value="{{ $inspection->id }}"><input type="hidden" name="items[{{ $rowIndex }}][kind]" value="{{ $kind }}"><input type="number" class="form-control form-control-sm" name="items[{{ $rowIndex }}][quantity]" aria-label="Jumlah {{ $label }} {{ $inspection->purchaseOrderItem->product->name }}" value="{{ old("items.$rowIndex.quantity",$max) }}" min="0" max="{{ $max }}" step="0.0001" required></td></tr>
@php $rowIndex++; @endphp
@endif
@endforeach
@endforeach
@if(!$rowIndex)<tr><td colspan="6" class="text-center text-muted p-4">Semua hasil pemeriksaan sudah dibuatkan retur/klaim.</td></tr>@endif
</tbody></table></div></div>
<div class="mb-3"><label class="form-label" for="returnNotes">Catatan Retur/Klaim</label><textarea class="form-control" id="returnNotes" name="notes" maxlength="5000" rows="2">{{ old('notes') }}</textarea></div>@if($rowIndex)<button class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan Draf Retur/Klaim</button>@endif <a class="btn btn-outline-secondary" href="{{ route('purchase.returns.index') }}">Kembali</a></form>
@endif
@endsection
