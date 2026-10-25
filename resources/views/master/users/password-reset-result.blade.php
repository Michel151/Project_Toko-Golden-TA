@extends('layouts.app')
@section('title', 'Password Sementara')
@section('page-title', 'Hasil Reset Password')
@section('content')
<div class="row justify-content-center"><div class="col-md-7 col-lg-6"><div class="card table-card"><div class="card-body">
    <h5 class="fw-bold">Password sementara berhasil dibuat</h5>
    <p>{{ $resetUser['name'] }} · {{ $resetUser['email'] }}</p>
    <label for="temporaryPassword" class="form-label fw-semibold">Password Sementara</label>
    <input id="temporaryPassword" class="form-control font-monospace mb-3" type="text" value="{{ $temporaryPassword }}" readonly autocomplete="off" spellcheck="false">
    <p class="small text-muted">Salin dan berikan password ini kepada pengguna. Password hanya ditampilkan sekali di halaman ini. Pengguna wajib menggantinya setelah masuk; sesi login dan akses ingat saya sebelumnya sudah dibatalkan.</p>
    <a href="{{ route('master.users.index') }}" class="btn btn-primary">Kembali ke Pengguna</a>
</div></div></div></div>
@endsection
