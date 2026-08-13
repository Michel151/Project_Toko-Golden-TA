<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DatabaseBackupService
{
    private const DIRECTORY = 'database-backups';

    private const FORMAT = 'toko-golden-backup-v1';

    private const EXCLUDED_TABLES = [
        'cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs', 'sessions',
    ];

    public function create(string $reason = 'manual'): array
    {
        $connection = DB::connection();
        $tables = array_values(array_diff($this->tableListing(), self::EXCLUDED_TABLES));
        sort($tables);

        $payload = [
            'format' => self::FORMAT,
            'created_at' => now()->toIso8601String(),
            'reason' => $reason,
            'driver' => $connection->getDriverName(),
            'tables' => [],
        ];

        foreach ($tables as $table) {
            $payload['tables'][$table] = $connection->table($table)->get()
                ->map(fn ($row) => (array) $row)
                ->all();
        }

        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $document = json_encode([
            'signature' => hash_hmac('sha256', $payloadJson, $this->signingKey()),
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $filename = 'backup-'.now()->format('Y-m-d_H-i-s').'-'.strtolower(str()->random(6)).'.json.gz';
        $compressed = gzencode($document, 9);

        if ($compressed === false || ! Storage::disk('local')->put(self::DIRECTORY.'/'.$filename, $compressed)) {
            throw new RuntimeException('File backup tidak dapat disimpan.');
        }

        return $this->details($filename);
    }

    public function all(): array
    {
        return collect(Storage::disk('local')->files(self::DIRECTORY))
            ->filter(fn ($path) => $this->isValidFilename(basename($path)))
            ->map(function ($path) {
                try {
                    return $this->details(basename($path));
                } catch (\Throwable) {
                    return [
                        'filename' => basename($path),
                        'created_at' => null,
                        'reason' => 'invalid',
                        'table_count' => 0,
                        'row_count' => 0,
                        'size' => Storage::disk('local')->size($path),
                        'valid' => false,
                    ];
                }
            })
            ->sortByDesc('filename')
            ->values()
            ->all();
    }

    public function restore(string $filename): void
    {
        $document = $this->read($filename);
        $payload = $document['payload'];
        $connection = DB::connection();
        $existingTables = $this->tableListing();
        $backupTables = array_keys($payload['tables']);

        if (array_diff($backupTables, $existingTables)) {
            throw new RuntimeException('Backup memuat tabel yang tidak tersedia pada aplikasi ini.');
        }

        $this->foreignKeyChecks(false);

        try {
            $connection->transaction(function () use ($connection, $payload, $backupTables) {
                foreach (array_reverse($backupTables) as $table) {
                    $connection->table($table)->delete();
                }

                foreach ($payload['tables'] as $table => $rows) {
                    foreach (array_chunk($rows, 250) as $chunk) {
                        if ($chunk !== []) {
                            $connection->table($table)->insert($chunk);
                        }
                    }
                }
            });
        } finally {
            $this->foreignKeyChecks(true);
        }
    }

    public function delete(string $filename): void
    {
        $this->assertFilename($filename);

        if (! Storage::disk('local')->delete(self::DIRECTORY.'/'.$filename)) {
            throw new RuntimeException('File backup tidak ditemukan atau tidak dapat dihapus.');
        }
    }

    public function path(string $filename): string
    {
        $this->assertFilename($filename);
        $path = self::DIRECTORY.'/'.$filename;

        if (! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('File backup tidak ditemukan.');
        }

        return Storage::disk('local')->path($path);
    }

    private function details(string $filename): array
    {
        $document = $this->read($filename);
        $payload = $document['payload'];
        $path = self::DIRECTORY.'/'.$filename;

        return [
            'filename' => $filename,
            'created_at' => $payload['created_at'],
            'reason' => $payload['reason'] ?? 'manual',
            'table_count' => count($payload['tables']),
            'row_count' => collect($payload['tables'])->sum(fn ($rows) => count($rows)),
            'size' => Storage::disk('local')->size($path),
            'valid' => true,
        ];
    }

    private function read(string $filename): array
    {
        $this->assertFilename($filename);
        $path = self::DIRECTORY.'/'.$filename;
        $compressed = Storage::disk('local')->get($path);

        if ($compressed === null || ($json = gzdecode($compressed)) === false) {
            throw new RuntimeException('File backup rusak atau tidak dapat dibaca.');
        }

        $document = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $payload = $document['payload'] ?? null;

        if (! is_array($payload) || ($payload['format'] ?? null) !== self::FORMAT || ! is_array($payload['tables'] ?? null)) {
            throw new RuntimeException('Format file backup tidak dikenali.');
        }

        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $expected = hash_hmac('sha256', $payloadJson, $this->signingKey());

        if (! hash_equals($expected, (string) ($document['signature'] ?? ''))) {
            throw new RuntimeException('Integritas file backup tidak valid.');
        }

        return $document;
    }

    private function foreignKeyChecks(bool $enabled): void
    {
        match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => DB::statement('SET FOREIGN_KEY_CHECKS='.(int) $enabled),
            'sqlite' => DB::statement('PRAGMA foreign_keys = '.($enabled ? 'ON' : 'OFF')),
            default => null,
        };
    }

    private function tableListing(): array
    {
        $connection = DB::connection();
        $schema = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
            ? $connection->getDatabaseName()
            : null;

        return $connection->getSchemaBuilder()->getTableListing($schema, false);
    }

    private function signingKey(): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException('APP_KEY belum dikonfigurasi.');
        }

        return $key;
    }

    private function assertFilename(string $filename): void
    {
        if (! $this->isValidFilename($filename)) {
            throw new RuntimeException('Nama file backup tidak valid.');
        }
    }

    private function isValidFilename(string $filename): bool
    {
        return preg_match('/^backup-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}-[a-z0-9]{6}\.json\.gz$/', $filename) === 1;
    }
}
