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
                           class="btn btn-sm btn-outline-info py-0 px-2" title="Riwayat PO">
                            <i class="bi bi-clock-history"></i>
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
                <tr><td colspan="6" class="text-center text-muted py-4">Belum ada supplier</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($suppliers->hasPages())
    <div class="card-footer bg-white">{{ $suppliers->links() }}</div>
    @endif
</div>
@endsection
