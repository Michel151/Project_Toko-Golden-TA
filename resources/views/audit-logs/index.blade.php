@extends('layouts.app')
@section('title', 'Audit Log')
@section('page-title', 'Audit Log')

@push('styles')
<style>
    .audit-heading { background: linear-gradient(120deg, #172039, #27324a); color: #fff; border-radius: 14px; padding: 1.2rem 1.3rem; }
    .audit-icon { width: 44px; height: 44px; border-radius: 12px; background: rgba(200, 150, 12, .18); color: #ffc107; display: grid; place-items: center; font-size: 1.2rem; }
    .audit-filter { background: #fff; border: 1px solid #e8ebf1; border-radius: 12px; padding: .85rem 1rem; }
    .audit-filter label { display: block; font-size: .67rem; text-transform: uppercase; letter-spacing: .04em; color: #7b8498; font-weight: 600; margin-bottom: .2rem; }
    .audit-table td { vertical-align: middle; padding: .7rem; }
    .audit-stat { border: 0; border-radius: 12px; box-shadow: 0 3px 12px rgba(25, 35, 55, .06); }
    .audit-change { min-height: 180px; max-height: 360px; overflow-y: auto; background: #f8f9fb; border: 1px solid #edf0f4; border-radius: 8px; padding: .25rem .8rem; }
    .audit-change-row { display: grid; grid-template-columns: minmax(130px, 42%) minmax(0, 1fr); gap: .75rem; padding: .7rem 0; border-bottom: 1px solid #e7eaf0; font-size: .82rem; }
    .audit-change-row:last-child { border-bottom: 0; }
    .audit-change-label { color: #697386; font-weight: 600; }
    .audit-change-value { color: #202737; font-weight: 500; overflow-wrap: anywhere; }
    .audit-change-empty { min-height: 165px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .45rem; color: #8a93a3; font-size: .82rem; }
    .audit-change-empty i { font-size: 1.35rem; }
    @media (max-width: 575.98px) { .audit-change-row { grid-template-columns: 1fr; gap: .2rem; } }
</style>
@endpush

@section('content')
<div class="audit-heading d-flex justify-content-between align-items-center mb-3">
    <div class="d-flex gap-3 align-items-center">
        <div class="audit-icon"><i class="bi bi-shield-lock"></i></div>
        <div><h5 class="fw-bold mb-1">Audit Log Sistem</h5><div class="small text-white-50">Jejak aktivitas pengguna dan perubahan data penting</div></div>
    </div>
    <span class="badge bg-warning text-dark">Khusus Owner</span>
</div>

<div class="row g-3 mb-3">
    <div class="col-4"><div class="card audit-stat"><div class="card-body"><div class="text-muted small">Aktivitas Hari Ini</div><div class="fs-5 fw-bold">{{ $todayCount }}</div></div></div></div>
    <div class="col-4"><div class="card audit-stat"><div class="card-body"><div class="text-muted small">Login Berhasil</div><div class="fs-5 fw-bold text-success">{{ $loginCount }}</div></div></div></div>
    <div class="col-4"><div class="card audit-stat"><div class="card-body"><div class="text-muted small">Login Gagal</div><div class="fs-5 fw-bold text-danger">{{ $failedLoginCount }}</div></div></div></div>
</div>

<div class="audit-filter mb-3">
    <form class="row g-2 align-items-end">
        <div class="col-lg-2"><label>Pencarian</label><input name="search" class="form-control form-control-sm" value="{{ request('search') }}" placeholder="Deskripsi..."></div>
        <div class="col-lg-2"><label>Pengguna</label><select name="user_id" class="form-select form-select-sm"><option value="">Semua Pengguna</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected(request('user_id') == $user->id)>{{ $user->name }}</option>@endforeach</select></div>
        <div class="col-lg-2"><label>Modul</label><select name="module" class="form-select form-select-sm"><option value="">Semua Modul</option>@foreach($modules as $module)<option value="{{ $module }}" @selected(request('module') === $module)>{{ $module }}</option>@endforeach</select></div>
        <div class="col-lg-1"><label>Aksi</label><select name="action" class="form-select form-select-sm"><option value="">Semua</option><option value="created" @selected(request('action') === 'created')>Buat</option><option value="updated" @selected(request('action') === 'updated')>Ubah</option><option value="deleted" @selected(request('action') === 'deleted')>Hapus</option><option value="login" @selected(request('action') === 'login')>Login</option><option value="logout" @selected(request('action') === 'logout')>Logout</option></select></div>
        <div class="col-lg-2"><label for="auditFrom">Dari Tanggal</label><input id="auditFrom" type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm"></div>
        <div class="col-lg-2"><label for="auditTo">Sampai Tanggal</label><input id="auditTo" type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm"></div>
        <div class="col-lg-1 d-flex gap-1"><button class="btn btn-sm btn-primary" title="Terapkan filter"><i class="bi bi-funnel"></i></button><a href="{{ route('audit-logs.index') }}" class="btn btn-sm btn-outline-secondary" title="Reset filter"><i class="bi bi-arrow-counterclockwise"></i></a></div>
    </form>
</div>

<div class="card table-card">
    <div class="card-header bg-white border-0 py-3"><h6 class="fw-bold mb-1">Riwayat Aktivitas</h6><small class="text-muted">{{ $auditLogs->total() }} aktivitas ditemukan</small></div>
    <div class="table-responsive">
        <table class="table table-hover audit-table mb-0">
            <thead><tr><th>Waktu</th><th>Pengguna</th><th>Modul</th><th>Aksi</th><th>Aktivitas</th><th>Alamat IP</th><th class="text-center">Detail</th></tr></thead>
            <tbody>
            @forelse($auditLogs as $log)
                @php
                    $colors = ['created' => 'success', 'updated' => 'warning', 'deleted' => 'danger', 'login' => 'primary', 'logout' => 'secondary', 'login_failed' => 'danger'];
                    $labels = ['created' => 'Membuat', 'updated' => 'Mengubah', 'deleted' => 'Menghapus', 'login' => 'Login', 'logout' => 'Logout', 'login_failed' => 'Login Gagal'];
                @endphp
                <tr>
                    <td class="text-nowrap"><div>{{ $log->created_at->format('d/m/Y') }}</div><small class="text-muted">{{ $log->created_at->format('H:i:s') }}</small></td>
                    <td><div class="fw-semibold">{{ $log->user->name ?? 'Tamu/Sistem' }}</div><small class="text-muted">{{ $log->user->role ?? '-' }}</small></td>
                    <td><span class="badge bg-light text-secondary">{{ $log->module }}</span></td>
                    <td><span class="badge bg-{{ $colors[$log->action] ?? 'secondary' }}">{{ $labels[$log->action] ?? $log->action }}</span></td>
                    <td>{{ $log->description }}</td>
                    <td class="font-monospace small text-muted">{{ $log->ip_address ?? '-' }}</td>
                    <td class="text-center">
                        @if($log->old_values || $log->new_values)
                            <button class="btn btn-sm btn-outline-primary py-0" data-bs-toggle="modal" data-bs-target="#auditModal{{ $log->id }}" title="Lihat detail"><i class="bi bi-eye"></i></button>
                        @else
                            <span class="text-muted">-</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-5"><i class="bi bi-shield-check fs-2 d-block mb-2"></i>Belum ada aktivitas audit</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($auditLogs->hasPages())<div class="card-footer bg-white">{{ $auditLogs->links() }}</div>@endif
</div>

@foreach($auditLogs as $log)
    @if($log->old_values || $log->new_values)
        @php($detail = $auditDetails[$log->id])
        <div class="modal fade" id="auditModal{{ $log->id }}" tabindex="-1" aria-labelledby="auditModalLabel{{ $log->id }}" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div><h6 class="modal-title fw-bold" id="auditModalLabel{{ $log->id }}">Detail Perubahan</h6><small class="text-muted">{{ $log->description }}</small></div>
                        <button class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <h6 class="small fw-bold text-danger mb-2">SEBELUM</h6>
                                <div class="audit-change">
                                    @include('audit-logs.partials.change-list', [
                                        'items' => $detail['old'],
                                        'emptyIcon' => $log->action === 'created' ? 'bi-file-earmark-plus' : 'bi-dash-circle',
                                        'emptyMessage' => $log->action === 'created' ? 'Data belum tersedia' : 'Tidak ada data sebelumnya',
                                    ])
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6 class="small fw-bold text-success mb-2">SESUDAH</h6>
                                <div class="audit-change">
                                    @include('audit-logs.partials.change-list', [
                                        'items' => $detail['new'],
                                        'emptyIcon' => $log->action === 'deleted' ? 'bi-trash' : 'bi-dash-circle',
                                        'emptyMessage' => $log->action === 'deleted' ? 'Data telah dihapus' : 'Tidak ada data terbaru',
                                    ])
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endforeach
@endsection
