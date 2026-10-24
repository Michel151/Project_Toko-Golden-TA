@extends('layouts.app')
@section('title', 'Transfer Stok')
@section('page-title', 'Transfer Stok')
@push('styles')
<style>
.transfer-filter-grid { display:grid; grid-template-columns:minmax(200px,1.4fr) repeat(2,minmax(140px,.8fr)) repeat(2,minmax(155px,1fr)) auto; align-items:end; gap:12px; }
.transfer-filter-grid .app-filter-field { min-width:0; }
.transfer-filter-grid .form-control,.transfer-filter-grid .form-select { width:100%; height:32px; }
.transfer-search-control { position:relative; }
.transfer-search-control > .bi { position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--app-muted); pointer-events:none; }
.transfer-search-control input { padding-left:32px; }
.transfer-filter-actions { display:flex; gap:8px; }
@media(max-width:1199px) {
    .transfer-filter-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .transfer-filter-search,.transfer-filter-actions { grid-column:1 / -1; }
}
@media(max-width:359px) { .transfer-filter-grid { grid-template-columns:minmax(0,1fr); } }
</style>
@endpush

@section('content')
<div class="inventory-heading d-flex flex-wrap gap-2 justify-content-between align-items-center"><h5><i class="bi bi-arrow-left-right me-2"></i>Transfer Stok</h5>
    @if(auth()->user()->canAccessRoute('inventory.transfers.create')) <a href="{{ route('inventory.transfers.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-circle me-1"></i>Transfer Baru
    </a> @endif
</div>
<div class="bg-white border-bottom p-3 mb-3">
    <form class="transfer-filter-grid" method="GET" id="transferFilterForm">
        @if(request('product_id'))
        <input type="hidden" name="product_id" value="{{ request('product_id') }}">
        @endif
        <div class="app-filter-field transfer-filter-search"><label for="transferLiveSearch">Cari Transfer</label><div class="transfer-search-control">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" name="search" id="transferLiveSearch" class="form-control form-control-sm"
                   placeholder="Nomor, barang, lokasi..."
                   value="{{ request('search') }}" autocomplete="off">
        </div></div>
        @include('components.date-range',['prefix'=>'transferList'])
        <div class="app-filter-field"><label for="transferListFromLocation">Lokasi Asal</label><select class="form-select form-select-sm" id="transferListFromLocation" name="from_location_id">
            <option value="">Semua Lokasi Asal</option>
            @foreach($locations as $location)<option value="{{ $location->id }}" @selected((string) request('from_location_id') === (string) $location->id)>{{ $location->name }}</option>@endforeach
        </select></div>
        <div class="app-filter-field"><label for="transferListToLocation">Lokasi Tujuan</label><select class="form-select form-select-sm" id="transferListToLocation" name="to_location_id">
            <option value="">Semua Lokasi Tujuan</option>
            @foreach($locations as $location)<option value="{{ $location->id }}" @selected((string) request('to_location_id') === (string) $location->id)>{{ $location->name }}</option>@endforeach
        </select></div>
        <div class="transfer-filter-actions"><button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Terapkan</button>
        <a href="{{ route('inventory.transfers.index') }}" id="transferResetFilter" title="Reset filter" aria-label="Reset filter" class="btn btn-sm btn-outline-secondary {{ request()->hasAny(['search','from','to','product_id','from_location_id','to_location_id']) ? '' : 'd-none' }}"><i class="bi bi-arrow-counterclockwise"></i></a></div>
    </form>
</div>

<div id="transferTableWrap" class="card table-card">
    <div class="card-body p-0 table-responsive">
        <table class="table table-hover align-middle mb-0" id="transferListTable">
            <thead>
                <tr>
                    <th>No. Transfer</th>
                    <th>Barang</th>
                    <th>Dari</th>
                    <th>Ke</th>
                    <th>Tanggal</th>
                    <th>Oleh</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($transfers as $transfer)
                <tr>
                    <td class="fw-semibold">{{ $transfer->transfer_number }}</td>
                    <td><button type="button" class="btn btn-sm btn-outline-success transfer-items-open" data-bs-toggle="modal" data-bs-target="#transferItemsModal" data-transfer-id="{{ $transfer->id }}" data-transfer-number="{{ $transfer->transfer_number }}" data-transfer-context="{{ $transfer->fromLocation->name }} &rarr; {{ $transfer->toLocation->name }} | {{ $transfer->transfer_date->format('d/m/Y') }}" aria-label="Rincian barang {{ $transfer->transfer_number }}"><i class="bi bi-list-ul me-1" aria-hidden="true"></i>{{ number_format($transfer->items->count(), 0, ',', '.') }} barang<i class="bi bi-chevron-right ms-2" aria-hidden="true"></i></button></td>
                    <td><span class="badge bg-danger bg-opacity-10 text-danger">{{ $transfer->fromLocation->name }}</span></td>
                    <td><span class="badge bg-success bg-opacity-10 text-success">{{ $transfer->toLocation->name }}</span></td>
                    <td>{{ $transfer->transfer_date->format('d/m/Y') }}</td>
                    <td class="text-muted small">{{ $transfer->createdBy->name ?? '-' }}</td>
                    <td class="text-center">
                        <a href="{{ route('inventory.transfers.show', $transfer) }}" class="btn btn-sm btn-outline-info py-0 px-2" title="Detail transfer" aria-label="Detail {{ $transfer->transfer_number }}"><i class="bi bi-eye"></i></a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center text-muted py-4">Belum ada transfer stok</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($transfers->hasPages())
    <div class="card-footer bg-white">{{ $transfers->links() }}</div>
    @endif
    @include('components.transfer-items-templates')
</div>
@include('components.transfer-items-modal')

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('transferFilterForm');
    const resetFilter = document.getElementById('transferResetFilter');
    const tableWrap = document.getElementById('transferTableWrap');

    function buildUrl() {
        const formData = new FormData(form);
        const params = new URLSearchParams();

        formData.forEach((value, key) => {
            if (String(value).trim() !== '') {
                params.set(key, value);
            }
        });

        const query = params.toString();
        const action = form.getAttribute('action') || window.location.pathname;
        return `${action}${query ? `?${query}` : ''}`;
    }

    async function fetchTransfers(url) {
        tableWrap.style.opacity = '0.55';

        try {
            const response = await fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html',
                },
            });
            if (!response.ok) throw new Error('Filter gagal dimuat');
            const html = await response.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const nextTable = doc.getElementById('transferTableWrap');

            if (nextTable) {
                tableWrap.innerHTML = nextTable.innerHTML;
                window.history.replaceState({}, '', url);
                resetFilter?.classList.toggle('d-none', !new URL(url, window.location.origin).search);
            } else {
                window.location.assign(url);
            }
        } catch (error) {
            window.location.assign(url);
        } finally {
            tableWrap.style.opacity = '';
        }
    }

    form?.addEventListener('submit', function (event) {
        event.preventDefault();
        fetchTransfers(buildUrl());
    });


    tableWrap?.addEventListener('click', function (event) {
        const pageLink = event.target.closest('.pagination a');
        if (!pageLink) return;

        event.preventDefault();
        fetchTransfers(pageLink.href);
    });
});
</script>
@endpush
@endsection
