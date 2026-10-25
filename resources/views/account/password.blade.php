@extends('layouts.app')
@section('title', 'Ubah Password')
@section('page-title', 'Ubah Password')
@section('content')
<div class="row justify-content-center"><div class="col-md-7 col-lg-6">
    <div class="card table-card"><div class="card-body">
        <h5 class="fw-bold mb-3">{{ auth()->user()->must_change_password ? 'Ganti Password Sementara' : 'Ubah Password' }}</h5>
        @if(auth()->user()->must_change_password)
        <div class="alert alert-warning">Anda wajib mengganti password sementara sebelum mengakses menu lain.</div>
        @endif
        <p class="small text-muted">{{ auth()->user()->name }} · {{ auth()->user()->email }}</p>
        <form method="POST" action="{{ route('account.password.update') }}">
            @csrf @method('PUT')
            <div class="mb-3">
                <label for="accountCurrentPassword" class="form-label fw-semibold">{{ auth()->user()->must_change_password ? 'Password Sementara' : 'Password Saat Ini' }}</label>
                <div class="input-group has-validation">
                    <input id="accountCurrentPassword" name="current_password" type="password" class="form-control @error('current_password') is-invalid @enderror" autocomplete="current-password" required>
                    <button type="button" class="btn btn-outline-secondary" data-password-toggle="accountCurrentPassword" data-password-label="password saat ini" aria-controls="accountCurrentPassword" aria-pressed="false" aria-label="Tampilkan password saat ini" title="Tampilkan password saat ini"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
            @include('components.password-fields', ['passwordOptional'=>false, 'passwordLabel'=>'Password Baru'])
            <div class="small text-muted mb-3">Setelah password diubah, sesi login di perangkat lain akan dibatalkan.</div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Simpan Password Baru</button>
        </form>
    </div></div>
</div></div>
@endsection
@push('scripts')
@include('components.password-scripts')
@endpush
