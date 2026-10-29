<?php

namespace App\Support;

class AccessPermissions
{
    public const ROLES = ['kasir' => 'Kasir', 'staf_gudang' => 'Staf Gudang'];

    public static function features(): array
    {
        return [
            'master.products' => ['label' => 'Barang', 'group' => 'Master Data', 'icon' => 'box-seam', 'actions' => ['view', 'create', 'edit', 'delete', 'import']],
            'master.categories' => ['label' => 'Kategori', 'group' => 'Master Data', 'icon' => 'tags', 'actions' => ['view', 'create', 'edit', 'delete']],
            'master.units' => ['label' => 'Satuan', 'group' => 'Master Data', 'icon' => 'rulers', 'actions' => ['view', 'create', 'edit', 'delete']],
            'master.locations' => ['label' => 'Lokasi/Gudang', 'group' => 'Master Data', 'icon' => 'geo-alt', 'actions' => ['view', 'create', 'edit', 'delete']],
            'master.suppliers' => ['label' => 'Supplier', 'group' => 'Master Data', 'icon' => 'truck', 'actions' => ['view', 'create', 'edit', 'delete', 'import']],
            'purchase.orders' => ['label' => 'Purchase Order', 'group' => 'Pembelian', 'icon' => 'cart-plus', 'actions' => ['view', 'create', 'edit', 'delete', 'confirm']],
            'purchase.receipts' => ['label' => 'Penerimaan Barang', 'group' => 'Pembelian', 'icon' => 'receipt', 'actions' => ['view', 'create']],
            'purchase.returns' => ['label' => 'Retur dan Klaim Supplier', 'group' => 'Pembelian', 'icon' => 'box-arrow-up', 'actions' => ['view', 'create', 'approve', 'dispatch']],
            'sale.sales' => ['label' => 'Transaksi Penjualan', 'group' => 'Penjualan', 'icon' => 'cash-stack', 'actions' => ['view', 'create', 'edit', 'delete']],
            'inventory.stock' => ['label' => 'Monitoring Stok', 'group' => 'Inventori', 'icon' => 'clipboard-data', 'actions' => ['view']],
            'inventory.transfers' => ['label' => 'Transfer Stok', 'group' => 'Inventori', 'icon' => 'arrow-left-right', 'actions' => ['view', 'create', 'edit', 'delete']],
            'inventory.opnames' => ['label' => 'Stock Opname', 'group' => 'Inventori', 'icon' => 'clipboard-check', 'actions' => ['view', 'create', 'edit', 'delete']],
            'report.stock' => ['label' => 'Laporan Stok', 'group' => 'Laporan', 'icon' => 'bar-chart', 'actions' => ['view']],
            'report.sale' => ['label' => 'Laporan Penjualan', 'group' => 'Laporan', 'icon' => 'graph-up', 'actions' => ['view']],
            'report.purchase' => ['label' => 'Laporan Pembelian', 'group' => 'Laporan', 'icon' => 'file-earmark-text', 'actions' => ['view']],
            'report.transfer' => ['label' => 'Laporan Transfer', 'group' => 'Laporan', 'icon' => 'arrow-repeat', 'actions' => ['view']],
        ];
    }

    public static function keys(): array
    {
        $keys = [];
        foreach (self::features() as $feature => $definition) {
            foreach ($definition['actions'] as $action) {
                $keys[] = "$feature.$action";
            }
        }

        return $keys;
    }

    public static function defaults(string $role): array
    {
        $features = match ($role) {
            'kasir' => ['sale.sales', 'inventory.stock'],
            'staf_gudang' => ['purchase.receipts', 'purchase.returns', 'inventory.stock', 'inventory.transfers', 'inventory.opnames'],
            default => [],
        };

        return array_values(array_filter(self::keys(), fn ($key) => $key !== 'purchase.returns.approve' && in_array(substr($key, 0, strrpos($key, '.')), $features, true)));
    }

    public static function forRoute(string $name): ?string
    {
        if (in_array($name, ['sale.drafts.index', 'sale.drafts.update'], true)) {
            return 'sale.sales.create';
        }
        if (str_starts_with($name, 'inventory.stock-card.')) {
            return in_array($name, ['inventory.stock-card.show', 'inventory.stock-card.pdf'], true) ? 'inventory.stock.view' : null;
        }
        if (in_array($name, ['sale.product.data', 'sale.stock.data'], true)) {
            return 'sale.sales.create';
        }
        foreach (self::features() as $feature => $definition) {
            if ($name !== $feature && ! str_starts_with($name, "$feature.")) {
                continue;
            }
            $suffix = $name === $feature ? 'index' : substr($name, strlen($feature) + 1);
            $action = match ($suffix) {
                'index', 'show', 'pdf', 'excel', 'print', 'label', 'movements' => 'view',
                'create', 'store', 'supplier-products', 'order-items', 'availability' => 'create',
                'edit', 'update', 'complete' => 'edit',
                'approve', 'reject' => 'approve',
                'dispatch' => 'dispatch',
                'confirm' => 'confirm',
                'close-remainder' => 'delete',
                'destroy', 'cancel' => 'delete',
                'import', 'import.template', 'import.store', 'import.preview', 'import-trial', 'import-trial.template', 'import-trial.preview', 'import-trial.store' => 'import',
                default => null,
            };

            return $action !== null && in_array($action, $definition['actions'], true) ? "$feature.$action" : null;
        }

        return null;
    }
}
