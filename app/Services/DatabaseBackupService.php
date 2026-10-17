<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DatabaseBackupService
{
    private const DIRECTORY = 'database-backups';

    private const FORMAT = 'toko-golden-backup-v1';

    public const MAX_UPLOAD_KILOBYTES = 20 * 1024;

    private const MAX_UNCOMPRESSED_BYTES = 100 * 1024 * 1024;

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

    public function exportSql(): array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException("Ekspor SQL belum mendukung driver database {$driver}.");
        }

        $tables = array_values(array_diff($this->tableListing(), self::EXCLUDED_TABLES));
        sort($tables);

        $lines = [
            '-- Toko Golden SQL Export',
            '-- Dibuat: '.now()->toIso8601String(),
            '-- Driver: '.$driver,
            '',
            $driver === 'sqlite' ? 'PRAGMA foreign_keys=OFF;' : 'SET FOREIGN_KEY_CHECKS=0;',
            '',
        ];

        foreach ($tables as $table) {
            $identifier = $this->quoteIdentifier($table, $driver);
            $lines[] = '-- Tabel: '.$table;
            $lines[] = 'DROP TABLE IF EXISTS '.$identifier.';';
            $lines[] = $this->createTableStatement($table, $driver).';';
            $lines[] = '';

            foreach ($connection->table($table)->orderBy($connection->getSchemaBuilder()->getColumnListing($table)[0])->cursor() as $row) {
                $values = (array) $row;
                $columns = array_map(fn (string $column) => $this->quoteIdentifier($column, $driver), array_keys($values));
                $serializedValues = array_map(fn (mixed $value) => $this->quoteSqlValue($value), array_values($values));

                $lines[] = 'INSERT INTO '.$identifier.' ('.implode(', ', $columns).') VALUES ('.implode(', ', $serializedValues).');';
            }

            $lines[] = '';
        }

        $lines[] = $driver === 'sqlite' ? 'PRAGMA foreign_keys=ON;' : 'SET FOREIGN_KEY_CHECKS=1;';
        $sql = implode("\n", $lines)."\n";
        $lines[] = '-- Toko-Golden-Signature: '.hash_hmac('sha256', $sql, $this->signingKey());
        $lines[] = '';

        $compressed = gzencode(implode("\n", $lines), 9);

        if ($compressed === false) {
            throw new RuntimeException('Ekspor SQL gagal dikompresi.');
        }

        return [
            'filename' => 'toko-golden-'.now()->format('Y-m-d_H-i-s').'.sql.gz',
            'contents' => $compressed,
        ];
    }

    public function import(UploadedFile $file): array
    {
        $originalName = strtolower($file->getClientOriginalName());

        if (! str_ends_with($originalName, '.json.gz')) {
            throw new RuntimeException('File harus menggunakan ekstensi .json.gz.');
        }

        if (! $file->isValid() || $file->getSize() === false || $file->getSize() > self::MAX_UPLOAD_KILOBYTES * 1024) {
            throw new RuntimeException('File backup gagal diunggah atau melebihi batas 20 MB.');
        }

        $compressed = $file->get();

        if ($compressed === false) {
            throw new RuntimeException('File backup tidak dapat dibaca.');
        }

        $this->decodeDocument($compressed);

        do {
            $filename = 'backup-'.now()->format('Y-m-d_H-i-s').'-'.strtolower(str()->random(6)).'.json.gz';
            $path = self::DIRECTORY.'/'.$filename;
        } while (Storage::disk('local')->exists($path));

        if (! Storage::disk('local')->put($path, $compressed)) {
            throw new RuntimeException('File backup tidak dapat disimpan.');
        }

        return $this->details($filename);
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

        if ($compressed === null) {
            throw new RuntimeException('File backup tidak ditemukan.');
        }

        return $this->decodeDocument($compressed);
    }

    private function decodeDocument(string $compressed): array
    {
        if (! str_starts_with($compressed, "\x1f\x8b")) {
            throw new RuntimeException('File bukan arsip gzip yang valid.');
        }

        if (strlen($compressed) < 4) {
            throw new RuntimeException('File backup rusak atau tidak dapat dibaca.');
        }

        $uncompressedSize = unpack('Vsize', substr($compressed, -4))['size'];

        if ($uncompressedSize > self::MAX_UNCOMPRESSED_BYTES) {
            throw new RuntimeException('Isi file backup melebihi batas keamanan 100 MB.');
        }

        $json = $this->decompressSafely($compressed);

        try {
            $document = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Isi file backup bukan JSON yang valid.');
        }

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

    private function decompressSafely(string $compressed): string
    {
        $context = inflate_init(ZLIB_ENCODING_GZIP);

        if ($context === false) {
            throw new RuntimeException('File backup rusak atau tidak dapat dibaca.');
        }

        $json = '';
        $length = strlen($compressed);

        for ($offset = 0; $offset < $length; $offset += 8192) {
            $chunk = substr($compressed, $offset, 8192);
            $mode = $offset + strlen($chunk) >= $length ? ZLIB_FINISH : ZLIB_SYNC_FLUSH;
            $decoded = @inflate_add($context, $chunk, $mode);

            if ($decoded === false) {
                throw new RuntimeException('File backup rusak atau tidak dapat dibaca.');
            }

            if (strlen($json) + strlen($decoded) > self::MAX_UNCOMPRESSED_BYTES) {
                throw new RuntimeException('Isi file backup melebihi batas keamanan 100 MB.');
            }

            $json .= $decoded;
        }

        return $json;
    }

    private function foreignKeyChecks(bool $enabled): void
    {
        match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => DB::statement('SET FOREIGN_KEY_CHECKS='.(int) $enabled),
            'sqlite' => DB::statement('PRAGMA foreign_keys = '.($enabled ? 'ON' : 'OFF')),
            default => null,
        };
    }

    private function createTableStatement(string $table, string $driver): string
    {
        $connection = DB::connection();

        if ($driver === 'sqlite') {
            $schema = $connection->selectOne(
                "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table]
            );

            if (! isset($schema->sql)) {
                throw new RuntimeException("Skema tabel {$table} tidak ditemukan.");
            }

            return $schema->sql;
        }

        $schema = (array) $connection->selectOne('SHOW CREATE TABLE '.$this->quoteIdentifier($table, $driver));
        $statement = array_values($schema)[1] ?? null;

        if (! is_string($statement) || $statement === '') {
            throw new RuntimeException("Skema tabel {$table} tidak ditemukan.");
        }

        return $statement;
    }

    private function quoteIdentifier(string $identifier, string $driver): string
    {
        return $driver === 'sqlite'
            ? '"'.str_replace('"', '""', $identifier).'"'
            : '`'.str_replace('`', '``', $identifier).'`';
    }

    private function quoteSqlValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $quoted = DB::connection()->getPdo()->quote((string) $value);

        if ($quoted === false) {
            throw new RuntimeException('Nilai database tidak dapat ditulis ke ekspor SQL.');
        }

        return $quoted;
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
