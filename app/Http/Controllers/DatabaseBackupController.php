<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\DatabaseBackupService;
use App\Services\SqlBackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class DatabaseBackupController extends Controller
{
    public function __construct(
        private readonly DatabaseBackupService $backups,
        private readonly SqlBackupService $sqlBackups
    ) {}

    public function index()
    {
        return view('database-backups.index', [
            'sqlBackups' => $this->sqlBackups->all(),
            'serverUploadLimit' => ini_get('upload_max_filesize'),
        ]);
    }

    public function store(): RedirectResponse
    {
        try {
            $backup = $this->backups->create();
            AuditService::record('create', 'database_backup', 'Membuat backup database '.$backup['filename']);

            return back()->with('success', 'Backup database berhasil dibuat.');
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'Backup gagal dibuat: '.$exception->getMessage());
        }
    }

    public function download(string $filename): BinaryFileResponse
    {
        $path = $this->backups->path($filename);
        AuditService::record('download', 'database_backup', 'Mengunduh backup database '.$filename);

        return response()->download($path, $filename, ['Content-Type' => 'application/gzip']);
    }

    public function exportSql(): Response
    {
        try {
            $export = $this->sqlBackups->export();
            AuditService::record('export_sql', 'database_backup', 'Mengekspor database sebagai SQL '.$export['filename']);

            if (isset($export['contents'])) {
                return response($export['contents'], 200, [
                    'Content-Type' => 'application/gzip',
                    'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
                    'Content-Length' => (string) strlen($export['contents']),
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }

            return response()->download($export['path'], $export['filename'], [
                'Content-Type' => 'application/gzip',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('database-backups.index')
                ->with('error', 'Ekspor SQL gagal: '.$exception->getMessage());
        }
    }

    public function downloadSql(string $filename): BinaryFileResponse
    {
        $path = $this->sqlBackups->path($filename);
        AuditService::record('download_sql', 'database_backup', 'Mengunduh backup SQL '.$filename);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/gzip',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function restoreSql(Request $request, string $filename): RedirectResponse
    {
        $this->validateRestoreConfirmation($request);

        if (! Hash::check($request->current_password, $request->user()->password)) {
            return back()->withErrors(['current_password' => 'Password owner tidak sesuai.']);
        }

        try {
            $result = $this->sqlBackups->restoreStored($filename);
            AuditService::record(
                'restore_sql',
                'database_backup',
                "Memulihkan database dari SQL {$filename}; backup pengaman: {$result['safety_filename']}"
            );

            return redirect()->route('database-backups.index')
                ->with('success', 'Database berhasil dipulihkan dari backup SQL. Backup pengaman dibuat otomatis.');
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'Pemulihan SQL gagal: '.$exception->getMessage());
        }
    }

    public function destroySql(Request $request, string $filename): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'string']]);

        if (! Hash::check($request->current_password, $request->user()->password)) {
            return back()->withErrors(['current_password' => 'Password owner tidak sesuai.']);
        }

        try {
            $this->sqlBackups->delete($filename);
            AuditService::record('delete_sql', 'database_backup', 'Menghapus backup SQL '.$filename);

            return back()->with('success', 'File backup SQL berhasil dihapus.');
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'Backup SQL gagal dihapus: '.$exception->getMessage());
        }
    }

    public function importSql(Request $request): RedirectResponse
    {
        $request->validate([
            'sql_backup_file' => [
                'required',
                'file',
                'max:'.config('database-backup.max_upload_kilobytes'),
            ],
            'current_password' => ['required', 'string'],
            'restore_confirmation' => ['accepted'],
        ], [
            'sql_backup_file.required' => 'Pilih file SQL yang akan dipulihkan.',
            'sql_backup_file.file' => 'File SQL yang dipilih tidak valid.',
            'sql_backup_file.max' => 'Ukuran file SQL melebihi batas aplikasi.',
            'restore_confirmation.accepted' => 'Konfirmasi pemulihan wajib dicentang.',
        ]);

        if (! Hash::check($request->current_password, $request->user()->password)) {
            return back()->withErrors(['current_password' => 'Password owner tidak sesuai.']);
        }

        try {
            $result = $this->sqlBackups->restore($request->file('sql_backup_file'));
            AuditService::record(
                'restore_sql',
                'database_backup',
                "Memulihkan database dari SQL {$result['original_filename']}; backup pengaman: {$result['safety_filename']}"
            );

            return redirect()->route('database-backups.index')
                ->with('success', 'Database berhasil dipulihkan dari SQL. Backup pengaman SQL dibuat otomatis.');
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'Import SQL gagal: '.$exception->getMessage());
        }
    }

    public function restore(Request $request, string $filename): RedirectResponse
    {
        $this->validateRestoreConfirmation($request);

        if (! Hash::check($request->current_password, $request->user()->password)) {
            return back()->withErrors(['current_password' => 'Password owner tidak sesuai.']);
        }

        try {
            $safetyBackup = $this->backups->create('before_restore');
            $this->backups->restore($filename);
            AuditService::record(
                'restore',
                'database_backup',
                "Memulihkan database dari {$filename}; backup pengaman: {$safetyBackup['filename']}"
            );

            return redirect()->route('database-backups.index')
                ->with('success', 'Database berhasil dipulihkan. Backup pengaman dibuat otomatis.');
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'Pemulihan gagal: '.$exception->getMessage());
        }
    }

    public function restoreUpload(Request $request): RedirectResponse
    {
        $this->validateRestoreConfirmation($request, true);

        if (! Hash::check($request->current_password, $request->user()->password)) {
            return back()->withErrors(['current_password' => 'Password owner tidak sesuai.']);
        }

        try {
            $importedBackup = $this->backups->import($request->file('backup_file'));
            $safetyBackup = $this->backups->create('before_restore');
            $this->backups->restore($importedBackup['filename']);
            AuditService::record(
                'restore_upload',
                'database_backup',
                "Mengimpor dan memulihkan database dari {$importedBackup['filename']}; backup pengaman: {$safetyBackup['filename']}"
            );

            return redirect()->route('database-backups.index')
                ->with('success', 'File berhasil diimpor dan database dipulihkan. Backup pengaman dibuat otomatis.');
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'Pemulihan dari file gagal: '.$exception->getMessage());
        }
    }

    public function destroy(Request $request, string $filename): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'string']]);

        if (! Hash::check($request->current_password, $request->user()->password)) {
            return back()->withErrors(['current_password' => 'Password owner tidak sesuai.']);
        }

        try {
            $this->backups->delete($filename);
            AuditService::record('delete', 'database_backup', 'Menghapus backup database '.$filename);

            return back()->with('success', 'File backup berhasil dihapus.');
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'Backup gagal dihapus: '.$exception->getMessage());
        }
    }

    private function validateRestoreConfirmation(Request $request, bool $withFile = false): void
    {
        $rules = [
            'current_password' => ['required', 'string'],
            'restore_confirmation' => ['accepted'],
        ];

        if ($withFile) {
            $rules['backup_file'] = ['required', 'file', 'max:'.DatabaseBackupService::MAX_UPLOAD_KILOBYTES];
        }

        $request->validate($rules, [
            'backup_file.required' => 'Pilih file backup yang akan dipulihkan.',
            'backup_file.file' => 'File backup yang dipilih tidak valid.',
            'backup_file.max' => 'Ukuran file backup maksimal 20 MB.',
            'restore_confirmation.accepted' => 'Konfirmasi pemulihan wajib dicentang.',
        ]);
    }
}
