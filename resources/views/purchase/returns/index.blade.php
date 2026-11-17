@extends('layouts.app')
@section('title', 'Retur dan Klaim Supplier')
@section('page-title', 'Retur dan Klaim Supplier')

@section('content')
@php
    $user = auth()->user();
    $canCreate = $user->canAccessRoute('purchase.returns.create');
    $canViewReceipt = $user->canAccessRoute('purchase.receipts.show');
    $statuses = ['draft' => 'Menunggu Persetujuan', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'];
    $statusColors = ['draft' => 'warning', 'approved' => 'success', 'rejected' => 'danger'];
    $summaryCards = [
        ['Perlu Retur/Klaim', $readyReceipts->total(), '#c8960c', 'Penerimaan dengan masalah yang belum diajukan'],
        ['Menunggu Persetujuan', $statusCounts->get('draft', 0), '#0d6efd', 'Pengajuan dalam periode riwayat'],
        ['Disetujui', $statusCounts->get('approved', 0), '#198754', 'Pengajuan dalam periode riwayat'],
    ];
@endphp

<div class="procurement-heading d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
    <div class="d-flex align-items-center gap-3">
        <div class="heading-icon"><i class="bi bi-box-arrow-up" aria-hidden="true"></i></div>
        <div>
            <h5 class="fw-bold mb-1">Retur dan Klaim Supplier</h5>
            <div class="small text-white-50">Tindak lanjuti barang rusak, tidak sesuai, atau kurang kirim</div>
        </div>
    </div>
    @if($canCreate)
        <a href="{{ route('purchase.returns.create') }}" class="btn btn-primary btn-sm px-3"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Buat Retur/Klaim</a>
    @endif
</div>

<div class="row g-3 mb-3">
    @foreach($summaryCards as [$label, $count, $color, $description])
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="card procurement-stat h-100" style="--accent:{{ $color }}">
                <div class="card-body">
                    <div class="text-muted small">{{ $label }}</div>
                    <div class="fs-5 fw-bold">{{ number_format($count, 0, ',', '.') }}</div>
                    <div class="text-muted small">{{ $description }}</div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<section class="card table-card mb-3" id="ready-returns" style="scroll-margin-top:80px">
    <div class="card-header bg-white border-0 py-3">
        <h6 class="fw-bold mb-1">Penerimaan Perlu Retur/Klaim</h6>
        <small class="text-muted">{{ $readyReceipts->total() }} penerimaan masih memiliki masalah yang belum diajukan, dari semua tanggal</small>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle procurement-table mb-0">
            <thead>
                <tr><th>No. PO</th><th>Supplier</th><th>Tanggal Terima</th><th>Masalah Belum Diajukan</th><th class="text-center">Aksi</th></tr>
            </thead>
            <tbody>
                @forelse($readyReceipts as $receipt)
                    @php $issues = $openIssues[$receipt->id]; @endphp
                    <tr>
                        <td class="text-nowrap">
                            <div class="font-monospace fw-semibold">{{ $receipt->purchaseOrder->po_number }}</div>
                            @if($canViewReceipt)
                                <a class="small text-decoration-none" href="{{ route('purchase.receipts.show', $receipt) }}">Penerimaan #{{ $receipt->id }}</a>
                            @else
                                <div class="small text-muted">Penerimaan #{{ $receipt->id }}</div>
                            @endif
                        </td>
                        <td>{{ $receipt->purchaseOrder->supplier->name }}</td>
                        <td class="text-nowrap">{{ $receipt->receipt_date->format('d/m/Y') }}</td>
                        <td>
                            @foreach([$issues->take(3), $issues->skip(3)] as $group)
                                @continue($group->isEmpty())
                                @if(!$loop->first)
                                    <details class="mt-1">
                                        <summary class="small text-primary">Lihat {{ $group->count() }} masalah lainnya</summary>
                                @endif
                                <ul class="list-unstyled mb-0">
                                    @foreach($group as $issue)
                                        <li class="py-1">
                                            <span class="fw-semibold">{{ $issue['product'] }}</span>
                                            <span class="badge bg-warning bg-opacity-10 text-warning-emphasis">{{ $issue['label'] }} · {{ \App\Support\DisplayNumber::quantity($issue['quantity']) }} {{ $issue['unit'] }}</span>
                                            @if($issue['location'])<span class="small text-muted">Ditahan di {{ $issue['location'] }}</span>@endif
                                        </li>
                                    @endforeach
                                </ul>
                                @if(!$loop->first)</details>@endif
                            @endforeach
                        </td>
                        <td class="text-center text-nowrap">
                            @if($canCreate)
                                <a href="{{ route('purchase.returns.create', ['receipt_id' => $receipt->id]) }}" class="btn btn-sm btn-outline-success"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Buat Retur/Klaim</a>
                            @elseif(!$canViewReceipt)
                                <span class="text-muted">—</span>
                            @else
                                <a href="{{ route('purchase.receipts.show', $receipt) }}" class="btn btn-sm btn-outline-primary" title="Detail penerimaan" aria-label="Detail penerimaan"><i class="bi bi-eye" aria-hidden="true"></i></a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-5"><i class="bi bi-check-circle fs-2 d-block mb-2" aria-hidden="true"></i>Tidak ada penerimaan yang perlu diajukan retur atau klaim.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($readyReceipts->total() > 0)
        <div class="card-footer bg-white">{{ $readyReceipts->links(data: ['itemLabel' => 'penerimaan']) }}</div>
    @endif
</section>

<section class="card table-card" id="return-history" style="scroll-margin-top:80px">
    <div class="card-header bg-white border-0 py-3">
        <h6 class="fw-bold mb-1">Riwayat Retur/Klaim</h6>
        <small class="text-muted">{{ $returns->total() }} dokumen ditemukan</small>
    </div>
    <form method="GET" action="{{ route('purchase.returns.index') }}#return-history" class="app-unified-filter app-filter-bar">
        @include('components.date-range', ['prefix' => 'returnList', 'fromValue' => $from, 'toValue' => $to])
        <div class="app-unified-field">
            <label for="returnStatus">Status</label>
            <select id="returnStatus" name="status" class="form-select form-select-sm">
                <option value="">Semua Status</option>
                @foreach($statuses as $key => $label)
                    <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="app-unified-actions">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Terapkan</button>
            <a href="{{ route('purchase.returns.index') }}#return-history" class="btn btn-sm btn-outline-secondary" title="Reset filter" aria-label="Reset filter"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset</a>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-hover align-middle procurement-table mb-0">
            <thead>
                <tr><th>Nomor Dokumen / Tanggal</th><th>PO Asal / Supplier</th><th>Status</th><th>PO Retur</th><th class="text-center">Aksi</th></tr>
            </thead>
            <tbody>
                @forelse($returns as $return)
                    <tr>
                        <td class="text-nowrap">
                            <a class="font-monospace fw-semibold text-decoration-none" href="{{ route('purchase.returns.show', $return) }}">{{ $return->return_number }}</a>
                            <div class="small text-muted">{{ $return->created_at->format('d/m/Y H:i') }}</div>
                        </td>
                        <td>
                            {{ $return->purchaseReceipt->purchaseOrder->po_number }}
                            <div class="small text-muted">{{ $return->purchaseReceipt->purchaseOrder->supplier->name }}</div>
                        </td>
                        <td><span class="badge bg-{{ $statusColors[$return->status] }}">{{ $statuses[$return->status] }}</span></td>
                        <td>
                            {{ $return->replacementOrder?->po_number ?? '-' }}
                            @if($return->replacementOrder)
                                <div class="small {{ $return->replacementOrder->status === 'completed' ? 'text-success' : 'text-muted' }}">
                                    {{ $return->replacementOrder->status === 'completed' ? 'Pemenuhan Selesai' : 'Menunggu Penerimaan' }}
                                </div>
                            @endif
                        </td>
                        <td class="text-center"><a class="btn btn-sm btn-outline-primary" title="Detail retur/klaim" aria-label="Detail retur/klaim" href="{{ route('purchase.returns.show', $return) }}"><i class="bi bi-eye" aria-hidden="true"></i></a></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-5"><i class="bi bi-inbox fs-2 d-block mb-2" aria-hidden="true"></i>Belum ada retur atau klaim supplier pada riwayat ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($returns->total() > 0)
        <div class="card-footer bg-white">{{ $returns->links(data: ['itemLabel' => 'dokumen']) }}</div>
    @endif
</section>
@endsection
