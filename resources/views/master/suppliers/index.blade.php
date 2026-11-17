{{-- Tampilkan kontak dan jumlah PO; form GET memakai q agar pencarian bisa dibawa pada URL. --}}
@extends('layouts.app')
@section('title', 'Supplier')
@section('page-title', 'Master Supplier')

@section('content')
@include('master._header', ['icon'=>'truck', 'title'=>'Data Supplier', 'description'=>'Kelola pemasok dan informasi kontak untuk proses pembelian.'])
<div class="d-flex flex-wrap gap-2 justify-content-end mb-3">
    @if(auth()->user()->canAccessRoute('master.suppliers.import'))<a href="{{ route('master.suppliers.import') }}" class="btn btn-outline-success btn-sm"><i class="bi bi-upload me-1"></i>Import Supplier</a>@endif
    @if(auth()->user()->canAccessRoute('master.suppliers.create')) <a href="{{ route('master.suppliers.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-circle me-1"></i> Tambah Supplier
    </a> @endif
</div>

<div class="card table-card">
    <form method="GET" action="{{ route('master.suppliers.index') }}" class="app-unified-filter app-filter-bar">
        <div class="app-unified-field app-unified-search">
            <label for="supplier-search">Cari Supplier</label>
            <input id="supplier-search" type="search" name="q" value="{{ $search }}"
                   class="form-control" placeholder="Nama, nomor telepon, atau alamat">
        </div>
        <div class="app-unified-actions">
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Terapkan</button>
            <a class="btn btn-outline-secondary" href="{{ route('master.suppliers.index') }}">
                <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset
            </a>
        </div>
    </form>
    <div class="card-body p-0 table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th width="50">#</th>
                    <th>Nama Supplier</th>
                    <th>No. Telepon</th>
                    <th>Alamat</th>
                    <th class="text-center">Total PO</th>
                    <th width="140" class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                {{-- purchase_orders_count berasal dari withCount; tautan detail menerima ID supplier dari baris ini. --}}
                @forelse($suppliers as $supplier)
                <tr>
                    <td class="text-muted">{{ $suppliers->firstItem() + $loop->index }}</td>
                    <td class="fw-semibold">{{ $supplier->name }}</td>
                    <td>{{ $supplier->phone ?? '-' }}</td>
                    <td class="text-muted small">{{ Str::limit($supplier->address, 40) ?? '-' }}</td>
                    <td class="text-center">
                        <span class="badge bg-info bg-opacity-10 text-info">{{ $supplier->purchase_orders_count }}</span>
                    </td>
                    <td class="text-center">
                        <a href="{{ route('master.suppliers.show', $supplier) }}"
                           class="btn btn-sm btn-outline-info py-0 px-2" title="Detail Supplier" aria-label="Detail Supplier">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </a>
                        @if(auth()->user()->canAccessRoute('master.suppliers.edit')) <a href="{{ route('master.suppliers.edit', $supplier) }}"
                           class="btn btn-sm btn-outline-warning py-0 px-2">
                            <i class="bi bi-pencil"></i>
                        </a> @endif
                        @if(auth()->user()->canAccessRoute('master.suppliers.destroy')) <form method="POST" action="{{ route('master.suppliers.destroy', $supplier) }}"
                              class="d-inline" data-confirm="Hapus supplier ini?">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger py-0 px-2">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form> @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="text-center text-muted py-4">{{ $search === '' ? 'Belum ada supplier.' : 'Tidak ada supplier yang sesuai pencarian.' }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($suppliers->total() > 0)
        <div class="card-footer bg-white">{{ $suppliers->links(data: ['itemLabel' => 'supplier']) }}</div>
    @endif
</div>
@endsection
