@extends('layouts.app')
@section('title', 'Backup Database')
@section('page-title', 'Backup & Pemulihan Database')

@push('styles')
<style>
.backup-hero{background:var(--app-header);color:#fff;border-radius:14px;padding:1.25rem}.backup-card{border:0;border-radius:14px;box-shadow:0 3px 16px rgba(25,35,55,.07)}.backup-icon{width:42px;height:42px;border-radius:10px;display:grid;place-items:center;background:#eef3ff;color:#0d6efd;font-size:1.2rem}.danger-note{background:#fff4f4;border:1px solid #f2cccc;border-radius:10px;padding:.85rem}
</style>
@endpush

@section('content')
<div class="backup-hero d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div>
        <h5 class="fw-bold mb-1"><i class="bi bi-database-check text-warning me-2"></i>Backup SQL Database</h5>
        <div class="small text-white-50">Ekspor dan pulihkan data berukuran besar menggunakan SQL terkompresi.</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-light fw-semibold" href="{{ route('database-backups.export-sql') }}" title="Unduh backup SQL terkompresi">
            <i class="bi bi-database-add me-1"></i>Backup
        </a>
        <button class="btn btn-warning fw-semibold" type="button" data-bs-toggle="modal" data-bs-target="#importSqlModal">
            <i class="bi bi-arrow-counterclockwise me-1"></i>Pulihkan
        </button>
    </div>
</div>


<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card backup-card h-100"><div class="card-body d-flex gap-3 align-items-center">
            <div class="backup-icon"><i class="bi bi-file-earmark-zip"></i></div>
            <div><div class="small text-muted">Format Backup</div><div class="fw-bold">SQL terkompresi</div><small class="text-muted">.sql.gz</small></div>
        </div></div>
    </div>
    <div class="col-md-8">
        <div class="card backup-card h-100"><div class="card-body">
            <div class="fw-semibold mb-1"><i class="bi bi-shield-check text-success me-2"></i>Keamanan Pemulihan</div>
            <div class="small text-muted">Restore hanya menerima <code>.sql</code> atau <code>.sql.gz</code> bertanda tangan yang dibuat aplikasi ini. Backup pengaman SQL dibuat sebelum data diganti.</div>
        </div></div>
    </div>
</div>

<div class="card backup-card">
    <div class="card-header bg-white border-0 py-3">
        <h6 class="fw-bold mb-1">Riwayat Backup SQL</h6>
        <small class="text-muted">File disimpan secara privat dan hanya dapat diakses oleh owner.</small>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Waktu</th><th>Nama File</th><th>Jenis</th><th>Ukuran</th><th>Status</th><th class="text-end">Aksi</th></tr></thead>
            <tbody>
            @forelse($sqlBackups as $backup)
                <tr>
                    <td class="text-nowrap">
                        <div class="fw-semibold">{{ \Illuminate\Support\Carbon::parse($backup['created_at'])->format('d/m/Y') }}</div>
                        <small class="text-muted">{{ \Illuminate\Support\Carbon::parse($backup['created_at'])->format('H:i:s') }}</small>
                    </td>
                    <td><code class="small">{{ $backup['filename'] }}</code></td>
                    <td>
                        @if($backup['reason'] === 'before-restore')
                            <span class="badge bg-info-subtle text-info-emphasis">Backup pengaman</span>
                        @else
                            <span class="badge bg-primary-subtle text-primary-emphasis">Manual</span>
                        @endif
                    </td>
                    <td>{{ $backup['size'] >= 1048576 ? number_format($backup['size'] / 1048576, 2).' MB' : number_format($backup['size'] / 1024, 1).' KB' }}</td>
                    <td><span class="badge bg-success-subtle text-success-emphasis"><i class="bi bi-check-circle me-1"></i>Tersimpan</span></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('database-backups.sql-download', $backup['filename']) }}" title="Unduh"><i class="bi bi-download"></i></a>
                        <button class="btn btn-sm btn-outline-warning" type="button" data-bs-toggle="modal" data-bs-target="#restoreSqlModal-{{ $loop->index }}" title="Pulihkan"><i class="bi bi-arrow-counterclockwise"></i></button>
                        <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#deleteSqlModal-{{ $loop->index }}" title="Hapus"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center py-5 text-muted"><i class="bi bi-database fs-2 d-block mb-2"></i>Belum ada backup SQL. Klik Backup untuk membuat backup.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@foreach($sqlBackups as $backup)
<div class="modal fade" id="restoreSqlModal-{{ $loop->index }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow">
        <form method="POST" action="{{ route('database-backups.sql-restore', $backup['filename']) }}">
            @csrf
            <div class="modal-header"><h5 class="modal-title fw-bold">Pulihkan Backup SQL</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="danger-note mb-3"><strong class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Perhatian</strong><div class="small mt-1">Seluruh data aktif akan diganti dengan isi <code>{{ $backup['filename'] }}</code>. Backup pengaman dibuat otomatis.</div></div>
                <label class="form-label fw-semibold" for="restore-sql-password-{{ $loop->index }}">Password owner</label>
                <input id="restore-sql-password-{{ $loop->index }}" type="password" name="current_password" class="form-control" required autocomplete="current-password" placeholder="Masukkan password untuk konfirmasi">
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" name="restore_confirmation" value="1" id="restore-sql-confirmation-{{ $loop->index }}" required>
                    <label class="form-check-label small" for="restore-sql-confirmation-{{ $loop->index }}">Saya memahami bahwa seluruh data aktif akan diganti.</label>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-warning fw-semibold"><i class="bi bi-arrow-counterclockwise me-1"></i>Pulihkan Database</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="deleteSqlModal-{{ $loop->index }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow">
        <form method="POST" action="{{ route('database-backups.sql-destroy', $backup['filename']) }}">
            @csrf @method('DELETE')
            <div class="modal-header"><h5 class="modal-title fw-bold">Hapus Backup SQL</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <p>File <code>{{ $backup['filename'] }}</code> akan dihapus permanen.</p>
                <label class="form-label fw-semibold" for="delete-sql-password-{{ $loop->index }}">Password owner</label>
                <input id="delete-sql-password-{{ $loop->index }}" type="password" name="current_password" class="form-control" required autocomplete="current-password" placeholder="Masukkan password untuk konfirmasi">
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Hapus Backup</button></div>
        </form>
    </div></div>
</div>
@endforeach

<div class="modal fade" id="importSqlModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow">
        <form method="POST" action="{{ route('database-backups.import-sql') }}" enctype="multipart/form-data">
            @csrf
            <div class="modal-header"><h5 class="modal-title fw-bold">Pulihkan Database</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="danger-note mb-3"><strong class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Perhatian</strong><div class="small mt-1">Seluruh data aktif akan diganti. File harus berasal dari tombol Backup pada aplikasi dengan <code>APP_KEY</code> yang sama.</div></div>
                <label class="form-label fw-semibold" for="sql-backup-file">File backup SQL</label>
                <input id="sql-backup-file" type="file" name="sql_backup_file" class="form-control" accept=".sql,.gz,application/sql,application/gzip" required>
                <div class="form-text">Format: <code>.sql</code> atau <code>.sql.gz</code>. Batas unggahan server saat ini: {{ $serverUploadLimit }}.</div>
                <label class="form-label fw-semibold mt-3" for="sql-current-password">Password owner</label>
                <input id="sql-current-password" type="password" name="current_password" class="form-control" required autocomplete="current-password" placeholder="Masukkan password untuk konfirmasi">
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" name="restore_confirmation" value="1" id="sql-restore-confirmation" required>
                    <label class="form-check-label small" for="sql-restore-confirmation">Saya memahami bahwa seluruh data aktif akan diganti.</label>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-warning fw-semibold"><i class="bi bi-arrow-counterclockwise me-1"></i>Pulihkan Database</button></div>
        </form>
    </div></div>
</div>
@endsection
