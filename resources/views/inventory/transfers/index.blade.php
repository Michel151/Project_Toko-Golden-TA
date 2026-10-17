@extends('layouts.app')
@section('title', 'Transfer Stok')
@section('page-title', 'Transfer Stok')

@section('content')
<div class="inventory-heading"><h5><i class="bi bi-arrow-left-right me-2"></i>Transfer Stok</h5></div>
<div class="d-flex justify-content-between align-items-center mb-3">
    <form class="d-flex flex-wrap gap-2 align-items-end" method="GET" id="transferFilterForm">
        @if(request('product_id'))
        <input type="hidden" name="product_id" value="{{ request('product_id') }}">
        @endif
        <div class="app-filter-field"><label for="transferLiveSearch">Cari Transfer</label><div class="input-group input-group-sm" style="width:260px">
            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
            <input type="search" name="search" id="transferLiveSearch" class="form-control"
                   placeholder="Cari no transfer, barang, lokasi..."
                   value="{{ request('search') }}" autocomplete="off">
        </div></div>
        @include('components.date-range',['prefix'=>'transferList'])
        <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Terapkan</button>
        <a href="{{ route('inventory.transfers.index') }}" id="transferResetFilter" class="btn btn-sm btn-outline-secondary {{ request()->hasAny(['search','from','to','product_id']) ? '' : 'd-none' }}"><i class="bi bi-arrow-counterclockwise"></i></a>
    </form>
    @if(auth()->user()->canAccessRoute('inventory.transfers.create')) <a href="{{ route('inventory.transfers.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-circle me-1"></i> Transfer Baru
    </a> @endif
</div>

<div id="transferTableWrap" class="card table-card">
    <div class="card-body p-0 table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>No. Transfer</th>
                    <th>Barang</th>
                    <th>Dari</th>
                    <th>Ke</th>
                    <th class="text-end">Jumlah</th>
                    <th>Tanggal</th>
                    <th>Oleh</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($transfers as $transfer)
                <tr>
                    <td class="fw-semibold">{{ $transfer->transfer_number }}</td>
                    <td>@foreach($transfer->items as $item)<div>{{ $item->product->name }}</div>@endforeach</td>
                    <td><span class="badge bg-danger bg-opacity-10 text-danger">{{ $transfer->fromLocation->name }}</span></td>
                    <td><span class="badge bg-success bg-opacity-10 text-success">{{ $transfer->toLocation->name }}</span></td>
                    <td class="text-end fw-semibold">@foreach($transfer->items as $item)<div>{{ $item->product->displayStock((float) $item->quantity) }}</div>@endforeach</td>
                    <td>{{ $transfer->transfer_date->format('d/m/Y') }}</td>
                    <td class="text-muted small">{{ $transfer->createdBy->name ?? '-' }}</td>
                    <td class="text-center">
                        <a href="{{ route('inventory.transfers.show', $transfer) }}" class="btn btn-sm btn-outline-info py-0 px-2"><i class="bi bi-eye"></i></a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">Belum ada transfer stok</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($transfers->hasPages())
    <div class="card-footer bg-white">{{ $transfers->links() }}</div>
    @endif
</div>

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
