@extends('layouts.app')
@section('title', isset($location) ? 'Edit Lokasi' : 'Tambah Lokasi')
@section('page-title', isset($location) ? 'Edit Lokasi' : 'Tambah Lokasi')

@section('content')
@include('master._header', ['icon'=>'geo-alt', 'title'=>isset($location) ? 'Edit Lokasi' : 'Tambah Lokasi', 'description'=>'Catat lokasi penyimpanan untuk pengelolaan stok yang akurat.'])
<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card table-card">
            <div class="card-body">
                <form method="POST" action="{{ isset($location)
                    ? route('master.locations.update', $location)
                    : route('master.locations.store') }}">
                    @csrf
                    @isset($location) @method('PUT') @endisset

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nama Lokasi <span class="text-danger">*</span></label>
                        <input type="text" name="name"
                               class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name', $location->name ?? '') }}"
                               placeholder="Contoh: Toko, Gudang 2, Gudang 3" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Deskripsi</label>
                        <textarea name="description" class="form-control" rows="2"
                                  placeholder="Keterangan singkat tentang lokasi ini">{{ old('description', $location->description ?? '') }}</textarea>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i>{{ isset($location) ? 'Perbarui' : 'Simpan' }}
                        </button>
                        <a href="{{ route('master.locations.index') }}" class="btn btn-outline-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
