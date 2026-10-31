@extends('layouts.app')
@section('title', 'Stock Opname')
@section('page-title', 'Stock Opname')

@section('content')
<div class="inventory-heading d-flex flex-wrap justify-content-between align-items-center gap-2">
    <h5><i class="bi bi-clipboard-check me-2"></i>Stock Opname</h5>
    @if(auth()->user()->canAccessRoute('inventory.opnames.create')) <a href="{{ route('inventory.opnames.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-circle me-1"></i> Buat Opname Baru
    </a> @endif
</div>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="btn-group btn-group-sm">
        <a href="{{ route('inventory.opnames.index') }}" class="btn btn-primary active" aria-current="page">
            <i class="bi bi-clipboard-check me-1"></i>Riwayat Opname
        </a>
        <a href="{{ route('inventory.opnames.movements') }}" class="btn btn-outline-secondary">
            <i class="bi bi-clock-history me-1"></i>Mutasi Barang
        </a>
    </div>
</div>

<form method="GET" class="app-unified-filter app-filter-bar mb-3">
    @include('components.date-range', ['prefix'=>'opnameList', 'fromValue'=>$from, 'toValue'=>$to])
    <div class="app-filter-actions">
        <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Terapkan</button>
        <a href="{{ route('inventory.opnames.index') }}" class="btn btn-sm btn-outline-secondary" title="Reset filter" aria-label="Reset filter"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset</a>
    </div>
</form>
<div class="card table-card">
    <div class="card-body p-0 table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>No. Opname</th>
                    <th>Lokasi</th>
                    <th>Tanggal</th>
                    <th class="text-center">Status</th>
                    <th>Dibuat Oleh</th>
                    <th class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($opnames as $opname)
                <tr>
                    <td class="fw-semibold">{{ $opname->opname_number }}</td>
                    <td>{{ $opname->location->name }}</td>
                    <td>{{ $opname->opname_date->format('d/m/Y') }}</td>
                    <td class="text-center">
                        <span class="badge bg-{{ $opname->status === 'completed' ? 'success' : 'secondary' }}">
                            {{ $opname->status === 'completed' ? 'Selesai' : 'Draf' }}
                        </span>
                    </td>
                    <td class="text-muted small">{{ $opname->createdBy->name ?? '-' }}</td>
                    <td class="text-center">
                        <a href="{{ route('inventory.opnames.show', $opname) }}" class="btn btn-sm btn-outline-info py-0 px-2"><i class="bi bi-eye"></i></a>
                        @if($opname->status === 'draft')
                        @if(auth()->user()->canAccessRoute('inventory.opnames.edit')) <a href="{{ route('inventory.opnames.edit', $opname) }}" class="btn btn-sm btn-outline-warning py-0 px-2"><i class="bi bi-pencil"></i></a> @endif
                        @endif
                        @if($opname->status === 'draft')
                        @if(auth()->user()->canAccessRoute('inventory.opnames.destroy')) <form method="POST" action="{{ route('inventory.opnames.destroy', $opname) }}" class="d-inline" data-confirm="Hapus draft opname ini?">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button>
                        </form> @endif
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="text-center text-muted py-4">Belum ada stock opname</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($opnames->hasPages())
    <div class="card-footer bg-white">{{ $opnames->links() }}</div>
    @endif
</div>
@endsection
