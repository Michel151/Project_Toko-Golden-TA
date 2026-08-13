@extends('layouts.app')
@section('title', 'Satuan')
@section('page-title', 'Master Satuan')

@section('content')
@include('master._header', ['icon'=>'rulers', 'title'=>'Satuan Barang', 'description'=>'Atur satuan pembelian, penjualan grosir, dan penjualan eceran.'])
<div class="d-flex justify-content-end mb-3">
    <a href="{{ route('master.units.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-circle me-1"></i> Tambah Satuan
    </a>
</div>

<div class="card table-card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th width="50">#</th>
                    <th>Nama Satuan</th>
                    <th class="text-center">Dipakai Sebagai Satuan Beli</th>
                    <th class="text-center">Dipakai Sebagai Satuan Ecer</th>
                    <th width="120" class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($units as $unit)
                <tr>
                    <td class="text-muted">{{ $units->firstItem() + $loop->index }}</td>
                    <td class="fw-semibold">{{ $unit->name }}</td>
                    <td class="text-center">
                        <span class="badge bg-info bg-opacity-10 text-info">{{ $unit->buy_unit_count }}</span>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-success bg-opacity-10 text-success">{{ $unit->retail_unit_count }}</span>
                    </td>
                    <td class="text-center">
                        <a href="{{ route('master.units.edit', $unit) }}"
                           class="btn btn-sm btn-outline-warning py-0 px-2">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <form method="POST" action="{{ route('master.units.destroy', $unit) }}"
                              class="d-inline" onsubmit="return confirm('Hapus satuan ini?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger py-0 px-2">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="text-center text-muted py-4">Belum ada satuan</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($units->hasPages())
    <div class="card-footer bg-white">{{ $units->links() }}</div>
    @endif
</div>
@endsection
