<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$backup = app(\App\Services\DatabaseBackupService::class)->create('before-expense-module');
if (!$backup['valid']) throw new RuntimeException('Cadangan database gagal diverifikasi.');
$sql = app(\App\Services\SqlBackupService::class)->export('before-expenses');
echo json_encode(['json' => $backup['filename'], 'sql' => $sql['filename'], 'valid' => true], JSON_PRETTY_PRINT).PHP_EOL;
