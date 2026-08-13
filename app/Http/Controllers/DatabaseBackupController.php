<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\DatabaseBackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DatabaseBackupController extends Controller
{
    public function __construct(private readonly DatabaseBackupService $backups) {}

    public function index()
    {
        return view('database-backups.index', ['backups' => $this->backups->all()]);
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

    public function restore(Request $request, string $filename): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'string']]);

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
}
