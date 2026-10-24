<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$filename = $argv[1] ?? '';
if (!preg_match('/^backup-[0-9_-]+-[a-z0-9]+\.json\.gz$/', $filename)) throw new RuntimeException('Nama cadangan tidak valid.');
$archive = \Illuminate\Support\Facades\Storage::disk('local')->get('database-backups/'.$filename);
$document = json_decode(gzdecode($archive), true, 512, JSON_THROW_ON_ERROR);
$tables = ['products', 'product_units', 'stocks', 'stock_batches', 'sales', 'sale_items', 'sale_item_sources',
    'purchase_orders', 'purchase_order_items', 'purchase_receipts', 'purchase_receipt_items', 'stock_opnames', 'stock_opname_items'];
$results = [];
foreach ($tables as $table) {
    $before = $document['payload']['tables'][$table] ?? throw new RuntimeException('Tabel tidak ada dalam cadangan: '.$table);
    $current = \Illuminate\Support\Facades\DB::table($table)->get()->keyBy('id');
    $changed = 0;
    foreach ($before as $row) {
        $now = $current->get($row['id']);
        foreach ($row as $column => $value) {
            if (!$now || ($value === null) !== ($now->$column === null) || (string) $value !== (string) $now->$column) {
                $changed++;
                break;
            }
        }
    }
    $results[$table] = ['before' => count($before), 'current' => $current->count(), 'old_rows_changed_or_missing' => $changed];
}
$results['moving_average'] = [
    'enabled' => app(\App\Services\MovingAverageCostService::class)->enabled(),
    'entries' => \Illuminate\Support\Facades\DB::table('inventory_cost_entries')->count(),
];
echo json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
if (collect($results)->except('moving_average')->contains(fn ($result) => $result['old_rows_changed_or_missing'] > 0)) exit(1);
