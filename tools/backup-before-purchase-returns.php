<?php

use App\Services\DatabaseBackupService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$service = app(DatabaseBackupService::class);
$backup = $service->create('before-purchase-inspections-and-returns');
if (! $backup['valid']) {
    throw new RuntimeException('Backup JSON gagal diverifikasi.');
}
$sql = $service->exportSql();
$path = 'database-backups/'.$sql['filename'];
if (! Storage::disk('local')->put($path, $sql['contents'])
    || gzdecode(Storage::disk('local')->get($path)) === false) {
    throw new RuntimeException('Backup SQL gagal diverifikasi.');
}
echo json_encode(['json' => $backup['filename'], 'sql' => $sql['filename'], 'tables' => $backup['table_count'],
    'rows' => $backup['row_count'], 'valid' => true], JSON_PRETTY_PRINT).PHP_EOL;
