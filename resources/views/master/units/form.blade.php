@extends('layouts.app')
@section('title', isset($unit) ? 'Edit Satuan' : 'Tambah Satuan')
@section('page-title', isset($unit) ? 'Edit Satuan' : 'Tambah Satuan')

@section('content')
@include('master._header', ['icon'=>'rulers', 'title'=>isset($unit) ? 'Edit Satuan' : 'Tambah Satuan', 'description'=>'Tentukan satuan yang digunakan pada transaksi dan stok.'])
<div class="row justify-content-center">
    <div class="col-md-5">
        <div class="card table-card">
            <div class="card-body">
                <form method="POST" action="{{ isset($unit)
                    ? route('master.units.update', $unit)
                    : route('master.units.store') }}">
                    @csrf
                    @isset($unit) @method('PUT') @endisset

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nama Satuan <span class="text-danger">*</span></label>
                        <input type="text" name="name"
                               class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name', $unit->name ?? '') }}"
                               placeholder="Contoh: Dus, Pak, Karung" required>
                        @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i>{{ isset($unit) ? 'Perbarui' : 'Simpan' }}
                        </button>
                        <a href="{{ route('master.units.index') }}" class="btn btn-outline-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
