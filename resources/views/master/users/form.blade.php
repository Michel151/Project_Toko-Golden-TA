@extends('layouts.app')
@section('title', isset($user) ? 'Ubah Pengguna' : 'Tambah Pengguna')
@section('page-title', isset($user) ? 'Ubah Pengguna' : 'Tambah Pengguna')

@section('content')
@include('master._header', ['icon'=>'person-gear', 'title'=>isset($user) ? 'Ubah Pengguna' : 'Tambah Pengguna', 'description'=>'Kelola identitas akun dan hak akses pengguna sistem.'])
<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card table-card">
            <div class="card-body">
                <form method="POST" action="{{ isset($user)
                    ? route('master.users.update', $user)
                    : route('master.users.store') }}">
                    @csrf
                    @isset($user) @method('PUT') @endisset

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" name="name"
                               class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name', $user->name ?? '') }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email"
                               class="form-control @error('email') is-invalid @enderror"
                               value="{{ old('email', $user->email ?? '') }}" required>
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="mb-3">
                        <label for="userPassword" class="form-label fw-semibold">
                            Password {{ isset($user) ? '(kosongkan jika tidak diganti)' : '' }}
                            @if(!isset($user)) <span class="text-danger">*</span> @endif
                        </label>
                        <input id="userPassword" type="password" name="password" autocomplete="new-password" aria-describedby="passwordRequirements"
                               class="form-control @error('password') is-invalid @enderror"
                               {{ !isset($user) ? 'required' : '' }}
                               minlength="8" placeholder="Masukkan password yang kuat">
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div id="passwordRequirements" class="form-text">Minimal 8 karakter, mengandung huruf besar, huruf kecil, angka, dan simbol (misalnya !, @, atau #).</div>
                    </div>

                    @if(!isset($user) || request()->routeIs('master.users.edit'))
                    <div class="mb-3">
                        <label for="userPasswordConfirmation" class="form-label fw-semibold">Konfirmasi Password</label>
                        <input id="userPasswordConfirmation" type="password" name="password_confirmation" class="form-control" autocomplete="new-password"
                               placeholder="Ulangi password" {{ !isset($user) ? 'required' : '' }}>
                    </div>
                    @endif

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                        <select name="role" class="form-select @error('role') is-invalid @enderror" required>
                            <option value="">-- Pilih Role --</option>
                            <option value="owner"      {{ old('role', $user->role ?? '') === 'owner'      ? 'selected' : '' }}>Owner</option>
                            <option value="kasir"      {{ old('role', $user->role ?? '') === 'kasir'      ? 'selected' : '' }}>Kasir</option>
                            <option value="staf_gudang"{{ old('role', $user->role ?? '') === 'staf_gudang'? 'selected' : '' }}>Staf Gudang</option>
                        </select>
                        @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i>{{ isset($user) ? 'Simpan Perubahan' : 'Simpan' }}
                        </button>
                        <a href="{{ route('master.users.index') }}" class="btn btn-outline-secondary">Batal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
