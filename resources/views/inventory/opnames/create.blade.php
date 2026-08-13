{{-- resources/views/inventory/opnames/create.blade.php --}}
@extends('layouts.app')
@section('title', 'Buat Stock Opname')
@section('page-title', 'Buat Stock Opname Baru')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-5">
        <div class="card table-card">
            <div class="card-body">
                <p class="text-muted small mb-3">Sistem akan membuat draft opname berisi semua barang di lokasi yang dipilih. Anda kemudian mengisi jumlah fisik setiap barang.</p>
                <form method="POST" action="{{ route('inventory.opnames.store') }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Lokasi <span class="text-danger">*</span></label>
                        <select name="location_id" class="form-select @error('location_id') is-invalid @enderror" required>
                            <option value="">-- Pilih Lokasi --</option>
                            @foreach($locations as $loc)
                            <option value="{{ $loc->id }}">{{ $loc->name }}</option>
                            @endforeach
                        </select>
                        @error('location_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Tanggal Opname <span class="text-danger">*</span></label>
                        <input type="date" name="opname_date" class="form-control" value="{{ date('Y-m-d') }}" required>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-clipboard-plus me-1"></i>Buat Draft</button>
                        <a href="{{ route('inventory.opnames.index') }}" class="btn btn-outline-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
