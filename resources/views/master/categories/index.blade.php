{{-- resources/views/master/categories/index.blade.php --}}
@extends('layouts.app')
@section('title', 'Kategori')
@section('page-title', 'Master Kategori')

@section('content')
@include('master._header', ['icon'=>'tags', 'title'=>'Kategori Barang', 'description'=>'Kelompokkan barang agar pencarian dan laporan lebih terstruktur.'])
<div class="d-flex justify-content-between align-items-center mb-3">
    <div></div>
    @if(auth()->user()->canAccessRoute('master.categories.create')) <a href="{{ route('master.categories.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-circle me-1"></i> Tambah Kategori
    </a> @endif
</div>

<div class="card table-card">
    <div class="card-body p-0 table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th width="50">#</th>
                    <th>Nama Kategori</th>
                    <th class="text-center">Jumlah Barang</th>
                    <th width="120" class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($categories as $category)
                <tr>
                    <td class="text-muted">{{ $categories->firstItem() + $loop->index }}</td>
                    <td class="fw-semibold">{{ $category->name }}</td>
                    <td class="text-center">
                        <span class="badge bg-primary bg-opacity-10 text-primary">
                            {{ $category->products_count }} barang
                        </span>
                    </td>
                    <td class="text-center">
                        @if(auth()->user()->canAccessRoute('master.categories.edit')) <a href="{{ route('master.categories.edit', $category) }}"
                           class="btn btn-sm btn-outline-warning py-0 px-2">
                            <i class="bi bi-pencil"></i>
                        </a> @endif
                        @if(auth()->user()->canAccessRoute('master.categories.destroy')) <form method="POST" action="{{ route('master.categories.destroy', $category) }}"
                              class="d-inline" data-confirm="Hapus kategori ini?">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger py-0 px-2">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form> @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="4" class="text-center text-muted py-4">Belum ada kategori</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($categories->hasPages())
    <div class="card-footer bg-white">{{ $categories->links() }}</div>
    @endif
</div>
@endsection
