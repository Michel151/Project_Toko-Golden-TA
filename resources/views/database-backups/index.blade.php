@extends('layouts.app')
@section('title', 'Backup Database')
@section('page-title', 'Backup & Pemulihan Database')

@push('styles')
<style>
.backup-hero{background:linear-gradient(120deg,#172039,#27324a);color:#fff;border-radius:14px;padding:1.25rem}.backup-card{border:0;border-radius:14px;box-shadow:0 3px 16px rgba(25,35,55,.07)}.backup-icon{width:42px;height:42px;border-radius:10px;display:grid;place-items:center;background:#eef3ff;color:#0d6efd;font-size:1.2rem}.danger-note{background:#fff4f4;border:1px solid #f2cccc;border-radius:10px;padding:.85rem}
</style>
@endpush

@section('content')
<div class="backup-hero d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h5 class="fw-bold mb-1"><i class="bi bi-database-check text-warning me-2"></i>Backup Database</h5>
        <div class="small text-white-50">Lindungi seluruh data transaksi, stok, dan master data Toko Golden.</div>
    </div>
    <form method="POST" action="{{ route('database-backups.store') }}">
        @csrf
        <button class="btn btn-warning fw-semibold" type="submit">
            <i class="bi bi-plus-circle me-1"></i>Buat Backup Sekarang
        </button>
    </form>
</div>

@if($errors->any())
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>{{ $errors->first() }}</div>
@endif

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card backup-card h-100"><div class="card-body d-flex gap-3 align-items-center">
            <div class="backup-icon"><i class="bi bi-archive"></i></div>
            <div><div class="small text-muted">Total Backup</div><div class="fs-4 fw-bold">{{ count($backups) }}</div></div>
        </div></div>
    </div>
    <div class="col-md-8">
        <div class="card backup-card h-100"><div class="card-body">
            <div class="fw-semibold mb-1"><i class="bi bi-shield-check text-success me-2"></i>Keamanan Pemulihan</div>
            <div class="small text-muted">Password owner wajib dimasukkan. Sistem juga membuat backup pengaman otomatis sebelum pemulihan dijalankan.</div>
        </div></div>
    </div>
</div>

<div class="card backup-card">
    <div class="card-header bg-white border-0 py-3">
        <h6 class="fw-bold mb-1">Riwayat Backup</h6>
        <small class="text-muted">File disimpan secara privat dan hanya dapat diakses oleh owner.</small>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Waktu</th><th>Nama File</th><th>Isi</th><th>Ukuran</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
            <tbody>
            @forelse($backups as $backup)
                <tr>
                    <td class="text-nowrap">
                        @if($backup['created_at'])
                            <div class="fw-semibold">{{ \Illuminate\Support\Carbon::parse($backup['created_at'])->format('d/m/Y') }}</div>
                            <small class="text-muted">{{ \Illuminate\Support\Carbon::parse($backup['created_at'])->format('H:i:s') }}</small>
                        @else
                            <span class="text-muted">Tidak diketahui</span>
                        @endif
                    </td>
                    <td><code class="small">{{ $backup['filename'] }}</code>@if($backup['reason'] === 'before_restore')<div><span class="badge bg-info-subtle text-info-emphasis mt-1">Backup pengaman</span></div>@endif</td>
                    <td><span class="fw-semibold">{{ number_format($backup['row_count']) }}</span> baris<br><small class="text-muted">{{ $backup['table_count'] }} tabel</small></td>
                    <td>{{ $backup['size'] >= 1048576 ? number_format($backup['size'] / 1048576, 2).' MB' : number_format($backup['size'] / 1024, 1).' KB' }}</td>
                    <td>@if($backup['valid'])<span class="badge bg-success-subtle text-success-emphasis"><i class="bi bi-check-circle me-1"></i>Valid</span>@else<span class="badge bg-danger-subtle text-danger-emphasis">Rusak</span>@endif</td>
                    <td class="text-end text-nowrap">
                        @if($backup['valid'])
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('database-backups.download', $backup['filename']) }}" title="Unduh"><i class="bi bi-download"></i></a>
                        <button class="btn btn-sm btn-outline-warning" type="button" data-bs-toggle="modal" data-bs-target="#restoreModal-{{ $loop->index }}" title="Pulihkan"><i class="bi bi-arrow-counterclockwise"></i></button>
                        @endif
                        <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#deleteModal-{{ $loop->index }}" title="Hapus"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center py-5 text-muted"><i class="bi bi-database fs-2 d-block mb-2"></i>Belum ada backup database.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@foreach($backups as $backup)
@if($backup['valid'])
<div class="modal fade" id="restoreModal-{{ $loop->index }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow">
        <form method="POST" action="{{ route('database-backups.restore', $backup['filename']) }}">
            @csrf
            <div class="modal-header"><h5 class="modal-title fw-bold">Pulihkan Database</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="danger-note mb-3"><strong class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Perhatian</strong><div class="small mt-1">Data aktif akan diganti dengan isi backup <code>{{ $backup['filename'] }}</code>. Jangan menutup halaman selama proses berlangsung.</div></div>
                <label class="form-label fw-semibold">Password owner</label>
                <input type="password" name="current_password" class="form-control" required autocomplete="current-password" placeholder="Masukkan password untuk konfirmasi">
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-warning fw-semibold"><i class="bi bi-arrow-counterclockwise me-1"></i>Pulihkan Database</button></div>
        </form>
    </div></div>
</div>
@endif

<div class="modal fade" id="deleteModal-{{ $loop->index }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow">
        <form method="POST" action="{{ route('database-backups.destroy', $backup['filename']) }}">
            @csrf @method('DELETE')
            <div class="modal-header"><h5 class="modal-title fw-bold">Hapus File Backup</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <p>File <code>{{ $backup['filename'] }}</code> akan dihapus permanen.</p>
                <label class="form-label fw-semibold">Password owner</label>
                <input type="password" name="current_password" class="form-control" required autocomplete="current-password" placeholder="Masukkan password untuk konfirmasi">
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-danger">Hapus Backup</button></div>
        </form>
    </div></div>
</div>
@endforeach
@endsection
