<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

class SqlBackupService
{
    private const DIRECTORY = 'database-backups/sql';

    private const SIGNATURE_PREFIX = '-- Toko-Golden-Signature: ';

    public function __construct(private readonly DatabaseBackupService $fallback) {}

    public function export(string $reason = 'manual'): array
    {
        if (! $this->usesMysql()) {
            $export = $this->fallback->exportSql();
            $filename = $this->generateFilename($reason);
            $relativePath = self::DIRECTORY.'/'.$filename;

            if (! Storage::disk('local')->put($relativePath, $export['contents'])) {
                throw new RuntimeException('File ekspor SQL tidak dapat disimpan.');
            }

            return [
                'filename' => $filename,
                'path' => Storage::disk('local')->path($relativePath),
                'relative_path' => $relativePath,
                'contents' => $export['contents'],
            ];
        }

        $filename = $this->generateFilename($reason);
        $relativePath = self::DIRECTORY.'/'.$filename;
        $absolutePath = Storage::disk('local')->path($relativePath);
        $directory = dirname($absolutePath);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Direktori ekspor SQL tidak dapat dibuat.');
        }

        $output = gzopen($absolutePath, 'wb9');

        if ($output === false) {
            throw new RuntimeException('File ekspor SQL tidak dapat dibuat.');
        }

        $hash = hash_init('sha256', HASH_HMAC, $this->signingKey());
        $errors = '';

        try {
            $process = $this->mysqlProcess($this->dumpCommand());
            $process->disableOutput();
            $process->run(function (string $type, string $buffer) use ($output, $hash, &$errors): void {
                if ($type === Process::ERR) {
                    $errors .= $buffer;

                    return;
                }

                hash_update($hash, $buffer);

                if (gzwrite($output, $buffer) === false) {
                    throw new RuntimeException('Ekspor SQL gagal ditulis ke penyimpanan.');
                }
            });

            if (! $process->isSuccessful()) {
                throw new RuntimeException('mysqldump gagal: '.$this->safeProcessError($errors));
            }

            $signature = hash_final($hash);
            gzwrite($output, self::SIGNATURE_PREFIX.$signature."\n");
        } catch (\Throwable $exception) {
            gzclose($output);
            Storage::disk('local')->delete($relativePath);

            throw $exception;
        }

        gzclose($output);

        return [
            'filename' => $filename,
            'path' => $absolutePath,
            'relative_path' => $relativePath,
        ];
    }

    public function all(): array
    {
        return collect(Storage::disk('local')->files(self::DIRECTORY))
            ->filter(fn (string $path) => $this->isValidFilename(basename($path)))
            ->map(function (string $path): array {
                $filename = basename($path);

                return [
                    'filename' => $filename,
                    'created_at' => Carbon::createFromTimestamp(Storage::disk('local')->lastModified($path))->toIso8601String(),
                    'reason' => str_starts_with($filename, 'toko-golden-before-restore-') ? 'before-restore' : 'manual',
                    'size' => Storage::disk('local')->size($path),
                ];
            })
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    public function path(string $filename): string
    {
        $this->assertFilename($filename);
        $path = self::DIRECTORY.'/'.$filename;

        if (! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('File backup SQL tidak ditemukan.');
        }

        return Storage::disk('local')->path($path);
    }

    public function delete(string $filename): void
    {
        $this->assertFilename($filename);

        if (! Storage::disk('local')->delete(self::DIRECTORY.'/'.$filename)) {
            throw new RuntimeException('File backup SQL tidak ditemukan atau tidak dapat dihapus.');
        }
    }

    public function restore(UploadedFile $file): array
    {
        $this->assertUpload($file);
        $sqlPath = $this->prepareSqlPath(
            $file->getRealPath(),
            str_ends_with(strtolower($file->getClientOriginalName()), '.gz')
        );

        try {
            return $this->restorePreparedSql($sqlPath, $file->getClientOriginalName());
        } finally {
            @unlink($sqlPath);
        }
    }

    public function restoreStored(string $filename): array
    {
        $sqlPath = $this->prepareSqlPath($this->path($filename), true);

        try {
            return $this->restorePreparedSql($sqlPath, $filename);
        } finally {
            @unlink($sqlPath);
        }
    }

    private function restorePreparedSql(string $sqlPath, string $originalFilename): array
    {
        $this->assertSignature($sqlPath);
        $safetyBackup = $this->export('before-restore');

        if ($this->usesMysql()) {
            $this->restoreMysql($sqlPath);
            DB::purge();
            DB::reconnect();
        } else {
            $sql = file_get_contents($sqlPath);

            if ($sql === false) {
                throw new RuntimeException('File SQL tidak dapat dibaca.');
            }

            DB::connection()->getPdo()->exec($sql);
        }

        Artisan::call('migrate', ['--force' => true]);

        return [
            'original_filename' => $originalFilename,
            'safety_filename' => $safetyBackup['filename'],
        ];
    }

    private function restoreMysql(string $sqlPath): void
    {
        $input = fopen($sqlPath, 'rb');

        if ($input === false) {
            throw new RuntimeException('File SQL tidak dapat dibuka untuk pemulihan.');
        }

        try {
            $process = $this->mysqlProcess($this->importCommand());
            $process->setInput($input);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('Import SQL gagal: '.$this->safeProcessError($process->getErrorOutput()));
            }
        } finally {
            fclose($input);
        }
    }

    private function prepareSqlPath(string $sourcePath, bool $compressed): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'toko-golden-sql-');

        if ($temporaryPath === false) {
            throw new RuntimeException('File sementara untuk import SQL tidak dapat dibuat.');
        }

        $source = $compressed ? gzopen($sourcePath, 'rb') : fopen($sourcePath, 'rb');
        $target = fopen($temporaryPath, 'wb');

        if ($source === false || $target === false) {
            @unlink($temporaryPath);
            throw new RuntimeException('File SQL tidak dapat dibaca.');
        }

        $total = 0;
        $limit = (int) config('database-backup.max_uncompressed_bytes');

        try {
            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);

                if ($chunk === false) {
                    throw new RuntimeException('File SQL rusak atau tidak dapat dibaca.');
                }

                $total += strlen($chunk);

                if ($total > $limit) {
                    throw new RuntimeException('Isi file SQL melebihi batas keamanan yang diizinkan.');
                }

                if ($chunk !== '' && fwrite($target, $chunk) === false) {
                    throw new RuntimeException('File sementara SQL tidak dapat ditulis.');
                }
            }
        } catch (\Throwable $exception) {
            fclose($source);
            fclose($target);
            @unlink($temporaryPath);

            throw $exception;
        }

        fclose($source);
        fclose($target);

        return $temporaryPath;
    }

    private function assertUpload(UploadedFile $file): void
    {
        $name = strtolower($file->getClientOriginalName());

        if (! str_ends_with($name, '.sql') && ! str_ends_with($name, '.sql.gz')) {
            throw new RuntimeException('File harus menggunakan ekstensi .sql atau .sql.gz.');
        }

        $maxBytes = (int) config('database-backup.max_upload_kilobytes') * 1024;

        if (! $file->isValid() || $file->getSize() === false || $file->getSize() > $maxBytes) {
            throw new RuntimeException('File SQL gagal diunggah atau melebihi batas ukuran.');
        }
    }

    private function generateFilename(string $reason): string
    {
        $safeReason = $reason === 'before-restore' ? $reason : 'manual';

        return 'toko-golden-'.$safeReason.'-'.now()->format('Y-m-d_H-i-s').'-'.strtolower(str()->random(6)).'.sql.gz';
    }

    private function assertFilename(string $filename): void
    {
        if (! $this->isValidFilename($filename)) {
            throw new RuntimeException('Nama file backup SQL tidak valid.');
        }
    }

    private function isValidFilename(string $filename): bool
    {
        return preg_match('/^toko-golden-(manual|before-restore)-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}-[a-z0-9]{6}\.sql\.gz$/', $filename) === 1;
    }

    private function assertSignature(string $path): void
    {
        $signatureLength = strlen(self::SIGNATURE_PREFIX) + 64 + 1;
        $size = filesize($path);

        if ($size === false || $size <= $signatureLength) {
            throw new RuntimeException('File SQL bukan ekspor resmi dari aplikasi ini.');
        }

        $stream = fopen($path, 'rb');

        if ($stream === false || fseek($stream, -$signatureLength, SEEK_END) !== 0) {
            throw new RuntimeException('Tanda tangan file SQL tidak dapat dibaca.');
        }

        $signatureLine = fread($stream, $signatureLength);

        if (! is_string($signatureLine) || preg_match('/^'.preg_quote(self::SIGNATURE_PREFIX, '/').'([a-f0-9]{64})\n$/', $signatureLine, $matches) !== 1) {
            fclose($stream);
            throw new RuntimeException('File SQL bukan ekspor resmi dari aplikasi ini.');
        }

        rewind($stream);
        $remaining = $size - $signatureLength;
        $hash = hash_init('sha256', HASH_HMAC, $this->signingKey());

        while ($remaining > 0) {
            $chunk = fread($stream, min(1024 * 1024, $remaining));

            if ($chunk === false || $chunk === '') {
                fclose($stream);
                throw new RuntimeException('Integritas file SQL tidak dapat diperiksa.');
            }

            hash_update($hash, $chunk);
            $remaining -= strlen($chunk);
        }

        fclose($stream);

        if (! hash_equals($matches[1], hash_final($hash))) {
            throw new RuntimeException('Integritas file SQL tidak valid.');
        }
    }

    private function dumpCommand(): array
    {
        $database = $this->databaseConfig();

        return [
            (string) config('database-backup.mysqldump_binary'),
            '--host='.$database['host'],
            '--port='.$database['port'],
            '--user='.$database['username'],
            '--default-character-set='.$database['charset'],
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--hex-blob',
            '--add-drop-table',
            $database['database'],
        ];
    }

    private function importCommand(): array
    {
        $database = $this->databaseConfig();

        return [
            (string) config('database-backup.mysql_binary'),
            '--host='.$database['host'],
            '--port='.$database['port'],
            '--user='.$database['username'],
            '--default-character-set='.$database['charset'],
            '--database='.$database['database'],
        ];
    }

    private function mysqlProcess(array $command): Process
    {
        $binary = $command[0];

        if (! is_file($binary) && str_contains($binary, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Utilitas database tidak ditemukan: {$binary}");
        }

        $process = new Process($command, null, $this->processEnvironment());
        $process->setTimeout(null);

        return $process;
    }

    private function processEnvironment(): array
    {
        $environment = ['MYSQL_PWD' => $this->databaseConfig()['password']];

        if (PHP_OS_FAMILY !== 'Windows') {
            return $environment;
        }

        foreach (['SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'PATH', 'COMSPEC', 'PATHEXT'] as $name) {
            $value = getenv($name);

            if ($value !== false && $value !== '') {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }

    private function databaseConfig(): array
    {
        $name = (string) config('database.default');
        $connection = (array) config("database.connections.{$name}");

        return [
            'host' => (string) ($connection['host'] ?? '127.0.0.1'),
            'port' => (string) ($connection['port'] ?? '3306'),
            'database' => (string) ($connection['database'] ?? ''),
            'username' => (string) ($connection['username'] ?? ''),
            'password' => (string) ($connection['password'] ?? ''),
            'charset' => (string) ($connection['charset'] ?? 'utf8mb4'),
        ];
    }

    private function usesMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function signingKey(): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException('APP_KEY belum dikonfigurasi.');
        }

        return $key;
    }

    private function safeProcessError(string $error): string
    {
        $message = trim($error);

        return $message === '' ? 'proses database berhenti tanpa pesan kesalahan.' : str($message)->limit(500)->toString();
    }
}
