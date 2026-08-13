@extends('layouts.app')
@section('title', 'Lokasi/Gudang')
@section('page-title', 'Master Lokasi/Gudang')

@section('content')
@include('master._header', ['icon'=>'geo-alt', 'title'=>'Lokasi & Gudang', 'description'=>'Kelola lokasi penyimpanan dan distribusi stok barang.'])
<div class="d-flex justify-content-end mb-3">
    <a href="{{ route('master.locations.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-circle me-1"></i> Tambah Lokasi
    </a>
</div>

<div class="card table-card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th width="50">#</th>
                    <th>Nama Lokasi</th>
                    <th>Deskripsi</th>
                    <th class="text-center">Jenis Barang</th>
                    <th width="120" class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($locations as $location)
                <tr>
                    <td class="text-muted">{{ $loop->iteration }}</td>
                    <td class="fw-semibold">
                        <i class="bi bi-geo-alt-fill text-primary me-1"></i>
                        {{ $location->name }}
                    </td>
                    <td class="text-muted">{{ $location->description ?? '-' }}</td>
                    <td class="text-center">
                        <span class="badge bg-primary bg-opacity-10 text-primary">
                            {{ $location->stocks_count }} jenis
                        </span>
                    </td>
                    <td class="text-center">
                        <a href="{{ route('master.locations.edit', $location) }}"
                           class="btn btn-sm btn-outline-warning py-0 px-2">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <form method="POST" action="{{ route('master.locations.destroy', $location) }}"
                              class="d-inline" onsubmit="return confirm('Hapus lokasi ini?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger py-0 px-2">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="text-center text-muted py-4">Belum ada lokasi</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
