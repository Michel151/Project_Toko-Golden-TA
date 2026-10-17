<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$file = new \Illuminate\Http\UploadedFile($argv[1], 'inspection.xlsx', test: true);
$sheets = app(\App\Services\TrialWorkbookReader::class)->read($file, 3, 5);
foreach ($sheets as $name => $rows) {
    echo json_encode(['sheet' => $name, 'headers' => $rows[0], 'header_hex' => array_map(fn ($v) => bin2hex((string) $v), $rows[0])], JSON_UNESCAPED_UNICODE).PHP_EOL;
}
$preview = app(\App\Services\ProductImportTrialService::class)->preview($sheets);
echo json_encode(['header_errors' => array_values(array_filter($preview['errors'], fn ($error) => str_contains($error['message'], 'Judul kolom'))), 'total_errors' => $preview['error_count']], JSON_UNESCAPED_UNICODE).PHP_EOL;
