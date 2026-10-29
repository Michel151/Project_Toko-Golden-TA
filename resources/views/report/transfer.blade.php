@extends('layouts.app')
@section('title', 'Laporan Transfer')
@section('page-title', 'Laporan Transfer Stok')

@section('content')
<div class="report-heading d-flex justify-content-between align-items-center mb-3"><div class="d-flex align-items-center gap-3"><div class="report-icon"><i class="bi bi-arrow-left-right"></i></div><div><h5 class="fw-bold mb-1">Laporan Transfer Stok</h5><div class="text-white-50 small">Lacak perpindahan barang antar lokasi penyimpanan</div></div></div><span class="badge bg-info text-dark">{{ $transfers->total() }} Transfer</span></div>
<div class="report-toolbar d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
    <form class="app-unified-filter d-flex flex-wrap gap-2 align-items-end" method="GET">
        @include('components.date-range',['prefix'=>'transferReport','fromValue'=>$from,'toValue'=>$to])
        <div><label class="form-label small mb-1" for="transferFromLocation">Lokasi Asal</label><select class="form-select form-select-sm" id="transferFromLocation" name="from_location_id">
            <option value="">Semua Lokasi Asal</option>
            @foreach($locations as $location)<option value="{{ $location->id }}" @selected((string) request('from_location_id') === (string) $location->id)>{{ $location->name }}</option>@endforeach
        </select></div>
        <div><label class="form-label small mb-1" for="transferToLocation">Lokasi Tujuan</label><select class="form-select form-select-sm" id="transferToLocation" name="to_location_id">
            <option value="">Semua Lokasi Tujuan</option>
            @foreach($locations as $location)<option value="{{ $location->id }}" @selected((string) request('to_location_id') === (string) $location->id)>{{ $location->name }}</option>@endforeach
        </select></div>
        <div><label for="transferReportPerPage" class="d-block">Per Halaman</label><select id="transferReportPerPage" name="per_page" class="form-select form-select-sm">
            @foreach([25, 50, 100] as $size)<option value="{{ $size }}" @selected($transfers->perPage() === $size)>{{ $size }}</option>@endforeach
        </select></div>
        <button class="btn btn-sm btn-primary px-3"><i class="bi bi-funnel me-1"></i>Terapkan</button>
    <a href="{{ route('report.transfer') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset</a></form>
    <div class="btn-group btn-group-sm">
        <a href="{{ route('report.transfer.pdf', request()->all()) }}" class="btn btn-outline-danger" target="_blank"><i class="bi bi-file-pdf me-1"></i>PDF</a>
        <a href="{{ route('report.transfer.excel', request()->all()) }}" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    </div>
</div>

@include('components.report-kpis',['metrics'=>[
    ['label'=>'Dokumen Transfer','value'=>number_format($transferSummary['documents'],0,',','.'),'hint'=>'Pada periode terpilih','icon'=>'file-earmark-text','tone'=>'primary'],
    ['label'=>'Jenis Barang Dipindahkan','value'=>number_format($transferSummary['products'],0,',','.'),'hint'=>'Jenis barang unik','icon'=>'box-seam','tone'=>'success'],
    ['label'=>'Lokasi Asal','value'=>number_format($transferSummary['origins'],0,',','.'),'hint'=>'Lokasi asal yang terlibat','icon'=>'box-arrow-up-right','tone'=>'warning'],
    ['label'=>'Lokasi Tujuan','value'=>number_format($transferSummary['destinations'],0,',','.'),'hint'=>'Lokasi tujuan yang terlibat','icon'=>'box-arrow-in-down','tone'=>'info'],
]])

<div class="card table-card">
    <div class="card-body p-0 table-responsive">
        <table class="table table-hover report-table mb-0" id="transferReportTable">
            <thead><tr><th>No. Transfer</th><th>Barang</th><th>Dari</th><th>Ke</th><th>Tanggal</th><th>Oleh</th></tr></thead>
            <tbody>
                @forelse($transfers as $t)
                <tr>
                    <td class="font-monospace small fw-semibold">{{ $t->transfer_number }}</td>
                    <td><button type="button" class="btn btn-sm btn-outline-success transfer-items-open" data-bs-toggle="modal" data-bs-target="#transferItemsModal" data-transfer-id="{{ $t->id }}" data-transfer-number="{{ $t->transfer_number }}" data-transfer-context="{{ $t->fromLocation->name }} &rarr; {{ $t->toLocation->name }} | {{ $t->transfer_date->format('d/m/Y') }}" aria-label="Rincian barang {{ $t->transfer_number }}"><i class="bi bi-list-ul me-1" aria-hidden="true"></i>{{ number_format($t->items->count(), 0, ',', '.') }} barang<i class="bi bi-chevron-right ms-2" aria-hidden="true"></i></button></td>
                    <td><span class="badge bg-primary bg-opacity-10 text-primary">{{ $t->fromLocation->name }}</span></td>
                    <td><span class="badge bg-success bg-opacity-10 text-success">{{ $t->toLocation->name }}</span></td>
                    <td>{{ $t->transfer_date->format('d/m/Y') }}</td>
                    <td class="text-muted small">{{ $t->createdBy->name ?? '-' }}</td>
                </tr>
                @empty
                <tr><td colspan="6" class="report-empty">@include('components.report-empty', ['message'=>'Tidak ada transfer pada periode ini'])</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white">{{ $transfers->links() }}</div>
</div>
@include('components.transfer-items-templates')
@include('components.transfer-items-modal')
@endsection
