@extends('layouts.app')
@section('title', isset($category) ? 'Ubah Kategori' : 'Tambah Kategori')
@section('page-title', isset($category) ? 'Ubah Kategori' : 'Tambah Kategori')

@section('content')
@include('master._header', ['icon'=>'tags', 'title'=>isset($category) ? 'Ubah Kategori' : 'Tambah Kategori', 'description'=>'Gunakan nama kategori yang singkat dan mudah dikenali.'])
<div class="row justify-content-center">
    <div class="col-md-5">
        <div class="card table-card">
            <div class="card-header bg-white border-0 pt-3">
                <h6 class="fw-bold mb-0">
                    <i class="bi bi-tags me-2"></i>
                    {{ isset($category) ? 'Ubah' : 'Tambah' }} Kategori
                </h6>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ isset($category)
                    ? route('master.categories.update', $category)
                    : route('master.categories.store') }}">
                    @csrf
                    @isset($category) @method('PUT') @endisset

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nama Kategori <span class="text-danger">*</span></label>
                        <input type="text" name="name"
                               class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name', $category->name ?? '') }}"
                               placeholder="Contoh: Bahan Pokok" required>
                        @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i>
                            {{ isset($category) ? 'Simpan Perubahan' : 'Simpan' }}
                        </button>
                        <a href="{{ route('master.categories.index') }}" class="btn btn-outline-secondary">
                            Batal
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
