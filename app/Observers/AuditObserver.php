<?php

namespace App\Observers;

use App\Services\AuditService;
use Illuminate\Database\Eloquent\Model;

class AuditObserver
{
    private array $sensitive = ['password', 'remember_token'];

    public function created(Model $model): void
    {
        AuditService::record('created', $this->module($model), 'Membuat ' . $this->label($model), $model, null, $this->clean($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $changes = $this->clean($model->getChanges());
        unset($changes['updated_at']);
        if (!$changes) return;

        $old = [];
        foreach (array_keys($changes) as $key) $old[$key] = $model->getOriginal($key);
        AuditService::record('updated', $this->module($model), 'Mengubah ' . $this->label($model), $model, $this->clean($old), $changes);
    }

    public function deleted(Model $model): void
    {
        AuditService::record('deleted', $this->module($model), 'Menghapus ' . $this->label($model), $model, $this->clean($model->getAttributes()), null);
    }

    private function clean(array $values): array
    {
        return collect($values)->except([...$this->sensitive, 'created_at', 'updated_at'])->toArray();
    }

    private function module(Model $model): string
    {
        return match (class_basename($model)) {
            'PurchaseOrder' => 'Purchase Order', 'PurchaseReceipt' => 'Penerimaan',
            'Sale' => 'Penjualan', 'StockTransfer' => 'Transfer Stok',
            'StockOpname' => 'Stock Opname', 'Product' => 'Barang',
            'Category' => 'Kategori', 'Unit' => 'Satuan', 'Location' => 'Lokasi',
            'Supplier' => 'Supplier', 'User' => 'Pengguna', default => class_basename($model),
        };
    }

    private function label(Model $model): string
    {
        $identity = $model->po_number ?? $model->sale_number ?? $model->transfer_number
            ?? $model->opname_number ?? $model->name ?? ('#' . $model->getKey());
        return $this->module($model) . ' ' . $identity;
    }
}
