<?php

// One-off CLI utility: inspect by default, execute only against an explicitly named database.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\DatabaseBackupService;

$tables = [
    'sale_drafts', 'product_import_runs', 'sale_item_sources', 'sale_items', 'sales',
    'stock_transfer_items', 'stock_transfers', 'stock_opname_items', 'stock_opnames',
    'stock_movements', 'stock_batches', 'purchase_receipt_items', 'purchase_receipts',
    'purchase_order_items', 'purchase_orders', 'product_supplier', 'product_locations',
    'stocks', 'product_units', 'products',
];
$preserved = ['users', 'suppliers', 'categories', 'units', 'locations', 'role_permissions', 'audit_logs'];
$db = DB::connection();
$database = $db->getDatabaseName();
$schema = $db->getSchemaBuilder();
$listing = $schema->getTableListing($database, false);
$fingerprint = function (string $table) use ($db): string {
    $rows = $db->table($table)->get()->map(fn ($row) => json_encode((array) $row, JSON_THROW_ON_ERROR))->all();
    sort($rows);
    return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
};
$before = [];
foreach (array_merge($tables, $preserved) as $table) {
    if (! in_array($table, $listing, true)) throw new RuntimeException("Missing table: {$table}");
    $before[$table] = $db->table($table)->count();
}
echo json_encode(['database' => $database, 'before' => $before], JSON_PRETTY_PRINT).PHP_EOL;
foreach ($listing as $table) {
    foreach ($schema->getForeignKeys($table) as $key) {
        if (in_array($key['foreign_table'], $tables, true) && ! in_array($table, $tables, true)) {
            throw new RuntimeException("Unexpected dependency: {$table} references {$key['foreign_table']}");
        }
    }
}
if (($argv[1] ?? '') !== '--execute') exit(0);
if (($argv[2] ?? '') !== $database || $database !== 'toko_golden') {
    throw new RuntimeException('Database confirmation does not match development database.');
}
$backupService = app(DatabaseBackupService::class);
$result = $db->transaction(function () use ($tables, $preserved, $fingerprint, $backupService, $db, $before) {
    $hashes = [];
    foreach ($preserved as $table) $hashes[$table] = $fingerprint($table);
    $backup = $backupService->create('before-development-product-and-transaction-reset');
    $backupPath = $backupService->path($backup['filename']);
    $document = json_decode(gzdecode(file_get_contents($backupPath)), true, flags: JSON_THROW_ON_ERROR);
    foreach ($before as $table => $count) {
        if (count($document['payload']['tables'][$table]) !== $count) {
            throw new RuntimeException("Backup count mismatch: {$table}");
        }
    }
    // DELETE keeps FK validation, rollback support and auto-increment IDs intact.
    foreach ($tables as $table) $db->table($table)->delete();
    foreach ($tables as $table) {
        if ($db->table($table)->exists()) throw new RuntimeException("Reset incomplete: {$table}");
    }
    foreach ($preserved as $table) {
        if ($fingerprint($table) !== $hashes[$table]) throw new RuntimeException("Preserved data changed: {$table}");
    }
    return ['backup' => $backupPath, 'deleted' => array_intersect_key($before, array_flip($tables)),
        'preserved' => array_intersect_key($before, array_flip($preserved))];
});
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
