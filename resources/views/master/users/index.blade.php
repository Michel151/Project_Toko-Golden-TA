@extends('layouts.app')
@section('title', 'Pengguna')
@section('page-title', 'Master Pengguna')

@section('content')
@include('master._header', ['icon'=>'people', 'title'=>'Pengguna Sistem', 'description'=>'Atur akun, peran, dan akses pengguna Toko Golden.'])
<div class="d-flex justify-content-end mb-3">
    <a href="{{ route('permissions.index') }}" class="btn btn-outline-secondary btn-sm me-2">
        <i class="bi bi-shield-check me-1"></i> Hak Akses
    </a>
    <a href="{{ route('master.users.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-circle me-1"></i> Tambah Pengguna
    </a>
</div>

<div class="card table-card">
    <div class="card-body p-0 table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th width="50">#</th>
                    <th>Nama</th>
                    <th>Email</th>
                    <th class="text-center">Role</th>
                    <th width="160" class="text-center">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @foreach($users as $user)
                <tr>
                    <td class="text-muted">{{ $users->firstItem() + $loop->index }}</td>
                    <td class="fw-semibold">
                        {{ $user->name }}
                        @if($user->id === auth()->id())
                        <span class="badge bg-secondary ms-1" style="font-size:0.65rem">Anda</span>
                        @endif
                        @if($user->must_change_password)<span class="badge bg-warning text-dark ms-1">Wajib ganti password</span>@endif
                    </td>
                    <td>{{ $user->email }}</td>
                    <td class="text-center">
                        @php
                            $roleLabels = ['owner' => 'Owner', 'kasir' => 'Kasir', 'staf_gudang' => 'Staf Gudang'];
                            $roleColors = ['owner' => 'purple', 'kasir' => 'primary', 'staf_gudang' => 'teal'];
                        @endphp
                        <span class="badge" style="background:{{ ['owner'=>'#6f42c1','kasir'=>'#0d6efd','staf_gudang'=>'#20c997'][$user->role] }}">
                            {{ $roleLabels[$user->role] }}
                        </span>
                    </td>
                    <td class="text-center">
                        <a href="{{ route('master.users.edit', $user) }}"
                           class="btn btn-sm btn-outline-warning py-0 px-2">
                            <i class="bi bi-pencil"></i>
                        </a>
                        @if($user->id !== auth()->id())
                        <form method="POST" action="{{ route('master.users.reset-password', $user) }}" class="d-inline" data-confirm="Reset password {{ $user->name }}? Sesi login lama akan dibatalkan. Pengguna wajib mengganti password sementara setelah masuk." data-confirm-title="Reset Password?" data-confirm-label="Reset Password">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Reset password {{ $user->name }}" aria-label="Reset password {{ $user->name }}"><i class="bi bi-key" aria-hidden="true"></i></button>
                        </form>
                        <form method="POST" action="{{ route('master.users.destroy', $user) }}"
                              class="d-inline" data-confirm="Hapus pengguna ini?">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger py-0 px-2">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if($users->hasPages())
    <div class="card-footer bg-white">{{ $users->links() }}</div>
    @endif
</div>
@endsection
