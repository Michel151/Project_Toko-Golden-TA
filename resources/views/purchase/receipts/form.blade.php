@extends('layouts.app')
@section('title', 'Catat Penerimaan Barang')
@section('page-title', 'Catat Penerimaan Barang')
@push('styles')
<style>
.receiving-table { min-width:980px; table-layout:fixed; }
.receiving-table th { white-space:nowrap; }
.receiving-table th:first-child { width:29%; }
.receiving-table th:nth-child(2) { width:13%; }
.receiving-table th:last-child { width:20%; }
.receiving-table .quantity { width:9.5%; }
.receiving-table td { padding:10px 8px; }
.inspection-row td:first-child { overflow-wrap:anywhere; }
.receiving-table .inspection-qty { width:100%; min-width:65px; }
.receiving-scroll { max-height:65vh; overflow:auto; }
.receiving-table thead { position:sticky; top:0; z-index:2; background:#eef2ed; }
.receiving-details { background:#f7f9f7; }
.receiving-detail-content { max-width:1050px; padding:4px 8px 8px; }
.receiving-detail-title { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:8px; margin-bottom:10px; }
.receiving-issues { display:grid; grid-template-columns:minmax(200px,1fr) minmax(240px,2fr); gap:12px; margin-top:12px; }
.receiving-issues.missing-only { grid-template-columns:1fr; }
.receiving-details[hidden],.receiving-issues[hidden],.quarantine-field[hidden] { display:none; }
.receiving-location { max-width:420px; }
.receiving-summary { font-size:12px; }
.receiving-toggle { display:inline-flex; align-items:center; gap:5px; padding:2px 0; border:0; background:none; color:var(--bs-success); font-size:12px; }
.receiving-toggle[aria-expanded="true"] .bi-chevron-right { transform:rotate(90deg); }
.receiving-detail-preview { font-size:12px; margin-top:2px; color:#59645d; }
.receiving-tools { display:flex; flex-wrap:wrap; align-items:end; gap:12px; padding:12px 16px; border-top:1px solid #dce2dc; }
.receiving-search { width:290px; max-width:100%; }
.receiving-filter { width:200px; }
.receiving-footer { display:flex; flex-wrap:wrap; align-items:center; gap:8px; padding:0 0 12px; }
.receipt-batch-grid { display:grid; grid-template-columns:130px 180px minmax(200px,1fr) 36px; gap:8px; align-items:center; }
.receipt-batch-grid + .receipt-batch-grid { margin-top:8px; }
.receipt-batch-grid .input-group-text { max-width:65px; overflow:hidden; }
.batch-delete { width:32px; height:32px; padding:0; }
@media(max-width:767px) { .receiving-scroll { max-height:none; } .receiving-tools > div { flex:1 1 180px; } .receiving-search,.receiving-filter { width:100%; } }
</style>
@endpush
@section('content')
<div class="procurement-heading mb-3 d-flex justify-content-between align-items-center">
    <h5 class="fw-bold mb-0"><i class="bi bi-box-arrow-in-down me-2"></i>Pemeriksaan Penerimaan</h5>
    @if($selectedOrder)<span class="badge bg-warning text-dark">{{ $selectedOrder->po_number }}</span>@endif
</div>
<form method="POST" action="{{ route('purchase.receipts.store') }}" id="receivingForm">
@csrf
<input type="hidden" name="submission_token" value="{{ old('submission_token', (string) Str::uuid()) }}">
<div class="bg-white border-bottom p-3 mb-3">
    <div class="row g-3">
        <div class="col-lg-5"><label class="form-label" for="poSelect">Purchase Order</label>
            <select name="purchase_order_id" id="poSelect" class="form-select" required>
                <option value="">Pilih PO</option>
                @foreach($pendingOrders as $po)<option value="{{ $po->id }}" @selected($selectedOrder?->id == $po->id)>{{ $po->po_number }} - {{ $po->supplier->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-lg-3"><label class="form-label" for="receiptDate">Tanggal Terima</label><input type="date" id="receiptDate" name="receipt_date" class="form-control" value="{{ old('receipt_date', today()->toDateString()) }}" required></div>
        <div class="col-lg-4"><label class="form-label" for="receiptNotes">Catatan Penerimaan</label><input id="receiptNotes" name="notes" class="form-control" maxlength="5000" value="{{ old('notes') }}"></div>
    </div>
</div>
@if($selectedOrder?->root_purchase_order_id)
<div class="alert alert-info">PO Retur dari {{ $selectedOrder->rootOrder->po_number }}. Tidak menambah tagihan pembelian.</div>
@endif
@if($selectedOrder)
<div class="bg-white border-bottom mb-3">
    <div class="p-3 d-flex flex-wrap gap-2 justify-content-between align-items-center"><h6 class="fw-bold mb-0">Hasil Pemeriksaan Barang</h6><div class="d-flex flex-wrap gap-3 align-items-center"><span id="inspectionCount" class="small text-muted" aria-live="polite">0 barang diperiksa</span><button class="btn btn-sm btn-outline-secondary" type="button" id="fillGood"><i class="bi bi-check2-all me-1"></i>Isi Sisa Barang Baik</button></div></div>
    <div class="receiving-tools">
        <div class="receiving-search"><label class="form-label small mb-1" for="receiptSearch">Cari Barang</label><input type="search" id="receiptSearch" class="form-control form-control-sm" placeholder="Nama barang atau barcode" data-receipt-filter></div>
        <div class="receiving-filter"><label class="form-label small mb-1" for="receiptFilter">Status Pemeriksaan</label><select id="receiptFilter" class="form-select form-select-sm" data-receipt-filter><option value="all">Semua Barang</option><option value="uninspected">Belum Diperiksa</option><option value="incomplete">Perlu Dilengkapi</option><option value="ready">Siap Disimpan</option></select></div>
        <span id="receiptVisibleCount" class="small text-muted ms-auto" aria-live="polite"></span>
    </div>
    <div class="table-responsive receiving-scroll">
    <table class="table align-middle receiving-table mb-0">
        <thead><tr><th>Barang / Satuan</th><th class="text-end">Sisa Penerimaan</th><th>Baik</th><th>Rusak</th><th>Tidak Sesuai</th><th>Kurang</th><th>Hasil</th></tr></thead>
        <tbody>
        @foreach($selectedOrder->items as $item)
        @if($item->receivableQuantity() > 0)
        @php $i = $loop->index; $unit = $item->productUnit?->unit?->name ?? $item->product->buyUnit->name; @endphp
        <tr class="inspection-row" data-max="{{ $item->receivableQuantity() }}" data-details="inspection-{{ $i }}" data-search="{{ mb_strtolower($item->product->name.' '.$item->product->barcode) }}" data-start-open="{{ $errors->has("items.$i.*") ? 'true' : 'false' }}">
            <td>
                <div class="fw-semibold">{{ $item->product->name }}</div>
                <div class="d-flex align-items-center gap-3"><small class="text-muted">{{ $unit }}</small><button type="button" class="receiving-toggle" aria-expanded="false" aria-controls="inspection-{{ $i }}" aria-label="Rincian {{ $item->product->name }}"><i class="bi bi-chevron-right" aria-hidden="true"></i>Rincian</button></div>
                <div class="receiving-detail-preview"></div>
                <input type="hidden" name="items[{{ $i }}][purchase_order_item_id]" value="{{ $item->id }}">
                <input type="hidden" name="items[{{ $i }}][product_id]" value="{{ $item->product_id }}">
                <input type="hidden" name="items[{{ $i }}][product_unit_id]" value="{{ $item->product_unit_id }}">
                @if(!$item->product->is_expirable)<input type="hidden" class="allocation-qty" name="items[{{ $i }}][allocations][0][quantity]" value="{{ old("items.$i.quantity_received", 0) }}">@endif
            </td>
            <td class="text-end">{{ rtrim(rtrim(number_format($item->receivableQuantity(), 4, ',', '.'),'0'),',') }}<br><small class="text-muted">{{ $unit }}</small></td>
            @foreach(['received' => 'Baik', 'damaged' => 'Rusak', 'wrong' => 'Tidak sesuai', 'missing' => 'Kurang'] as $field => $label)
            <td class="quantity"><input type="number" aria-label="{{ $label }} {{ $item->product->name }}" class="form-control form-control-sm inspection-qty {{ $field === 'received' ? 'good-qty' : 'exception-qty' }}" name="items[{{ $i }}][quantity_{{ $field }}]" value="{{ old("items.$i.quantity_$field", 0) }}" min="0" max="{{ $item->receivableQuantity() }}" step="0.0001" required></td>
            @endforeach
            <td class="receiving-summary small" aria-live="polite"></td>
        </tr>
        <tr id="inspection-{{ $i }}" class="receiving-details" hidden><td colspan="7"><div class="receiving-detail-content">
            <div class="receiving-detail-title"><strong class="small">{{ $item->product->name }}</strong><button type="button" class="btn btn-sm btn-outline-secondary detail-close" title="Tutup rincian" aria-label="Tutup rincian {{ $item->product->name }}"><i class="bi bi-chevron-up" aria-hidden="true"></i></button></div>
            @if($item->product->is_expirable)
            @php
                $batchRows = array_values(old("items.$i.batches", [[
                    'quantity' => old("items.$i.quantity_received", 0),
                    'location_id' => $locations->first()?->id,
                    'expired_date' => old("items.$i.expired_date"),
                ]]));
                if (!$batchRows) $batchRows = [['quantity' => 0]];
            @endphp
            <div class="batch-panel mb-3" data-item-index="{{ $i }}" data-next-index="{{ count($batchRows) }}">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2"><strong class="small">Rincian Kedaluwarsa</strong><div class="d-flex flex-wrap gap-3 align-items-center"><span class="batch-summary small" aria-live="polite"></span><button type="button" class="btn btn-sm btn-outline-primary batch-add"><i class="bi bi-plus-lg me-1"></i>Tambah Kedaluwarsa</button></div></div>
                <div class="receipt-batch-grid small text-muted mb-1"><span>Jumlah ({{ $unit }})</span><span>Kedaluwarsa</span><span>Lokasi</span><span></span></div>
                <div class="batch-list">
                @foreach($batchRows as $batchIndex => $batchValues)
                    @include('purchase.receipts.batch-row')
                @endforeach
                </div>
                <template class="batch-template">@include('purchase.receipts.batch-row', ['batchIndex' => '__BATCH__', 'batchValues' => ['quantity' => 0, 'location_id' => $locations->first()?->id]])</template>
            </div>
            @endif
                @if(!$item->product->is_expirable)
                <div class="receiving-location"><label class="form-label small" for="good-location-{{ $i }}">Lokasi Barang Baik</label><select class="form-select form-select-sm good-location" id="good-location-{{ $i }}" name="items[{{ $i }}][allocations][0][location_id]">
                    @foreach($locations as $loc)<option value="{{ $loc->id }}" @selected(old("items.$i.allocations.0.location_id") == $loc->id)>{{ $loc->name }}</option>@endforeach
                </select></div>
                @endif
            <div class="receiving-issues" hidden>
                <div class="quarantine-field"><label class="form-label small" for="quarantine-{{ $i }}">Lokasi Barang Retur</label><select class="form-select form-select-sm quarantine-location" id="quarantine-{{ $i }}" name="items[{{ $i }}][quarantine_location_id]"><option value="">Pilih lokasi</option>
                    @foreach($locations as $loc)<option value="{{ $loc->id }}" @selected(old("items.$i.quarantine_location_id") == $loc->id)>{{ $loc->name }}</option>@endforeach
                </select></div>
                <div><label class="form-label small" for="reason-{{ $i }}">Keterangan Masalah</label><input id="reason-{{ $i }}" class="form-control form-control-sm inspection-reason" name="items[{{ $i }}][reason]" maxlength="2000" value="{{ old("items.$i.reason") }}"></div>
            </div>
        </div></td></tr>
        @endif
        @endforeach
        <tr id="receiptNoResults" hidden><td colspan="7" class="text-center text-muted py-4">Tidak ada barang yang sesuai pencarian atau filter.</td></tr>
        @if(!$selectedOrder->items->contains(fn ($item) => $item->receivableQuantity() > 0))
        <tr><td colspan="7" class="text-center text-muted p-4">Tidak ada sisa penerimaan. Sisa bermasalah dilanjutkan melalui retur/klaim dan PO Retur.</td></tr>
        @endif
        </tbody>
    </table>
    </div>
</div>
@endif
<div class="receiving-footer">@if($selectedOrder)<button class="btn btn-primary" type="submit"><i class="bi bi-check-circle me-1"></i>Simpan Penerimaan</button>@endif<a class="btn btn-outline-secondary" href="{{ route('purchase.receipts.index') }}">Kembali</a></div>
</form>
@push('scripts')
<script src="{{ asset('js/purchase-receiving.js') }}"></script>
@endpush
@endsection
