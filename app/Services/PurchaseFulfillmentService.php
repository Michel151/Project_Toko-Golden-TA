<?php

namespace App\Services;

use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseFulfillmentService
{
    // Menyiapkan service untuk menambah stok dari barang yang diterima baik.
    public function __construct(private StockService $stockService) {}

    // Mencatat pemeriksaan, menambah stok barang baik, dan memperbarui pemenuhan PO beserta sumbernya.
    public function receive(array $data, int $userId): PurchaseReceipt
    {
        return DB::transaction(function () use ($data, $userId) {
            // Mengunci perubahan nilai persediaan sebelum stok penerimaan ditambahkan.
            app(MovingAverageCostService::class)->lock();
            $order = $this->lockOrder((int) $data['purchase_order_id']);
            // Mengembalikan penerimaan yang sama jika token form ini sudah pernah diproses.
            if (! empty($data['submission_token']) && $existing = PurchaseReceipt::where('submission_token', $data['submission_token'])->first()) {
                if ((int) $existing->purchase_order_id !== $order->id || (int) $existing->received_by !== $userId) {
                    $this->invalid('submission_token', 'Token penerimaan tidak sesuai. Muat ulang formulir.');
                }

                return $existing;
            }
            if (!in_array($order->status, ['pending', 'partial'], true)) {
                $this->invalid('purchase_order_id', 'PO harus sudah dikonfirmasi dan belum dibatalkan atau ditutup untuk menerima barang.');
            }
            $receipt = $order->receipts()->create([
                'receipt_date' => $data['receipt_date'], 'received_by' => $userId, 'notes' => $data['notes'] ?? null,
                'submission_token' => $data['submission_token'] ?? null,
            ]);
            $processed = false;
            foreach ($data['items'] as $index => $row) {
                $item = $order->items()->lockForUpdate()->find($row['purchase_order_item_id']);
                if (! $item || (int) $item->product_id !== (int) $row['product_id']
                    || (int) $item->product_unit_id !== (int) $row['product_unit_id']) {
                    $this->invalid("items.$index", 'Barang dan satuan tidak sesuai dengan PO yang dipilih.');
                }
                $good = round((float) $row['quantity_received'], 4);
                $exceptions = [];
                foreach (PurchaseReturnItem::KINDS as $kind => $label) {
                    $exceptions[$kind] = round((float) ($row['quantity_'.$kind] ?? 0), 4);
                }
                // Jumlah masalah dicadangkan agar tidak diterima ulang sebagai sisa pesanan biasa.
                $reserved = array_sum($exceptions);
                $total = $good + $reserved;
                if ($total > $item->receivableQuantity() + 0.00001) {
                    $this->invalid("items.$index.quantity_received", "Total pemeriksaan {$item->product->name} melebihi sisa yang dapat diterima. Muat ulang PO untuk melihat jumlah terbaru.");
                }
                $product = $item->product;
                $allocations = $this->receiptAllocations($row, $product, $index, $good);
                if ($total <= 0) {
                    continue;
                }
                $processed = true;
                $reason = trim($row['reason'] ?? '');
                if ($reserved > 0 && $reason === '') {
                    $this->invalid("items.$index.reason", 'Isi keterangan untuk barang rusak, tidak sesuai, atau kurang.');
                }
                if ($exceptions['damaged'] + $exceptions['wrong'] > 0 && empty($row['quarantine_location_id'])) {
                    $this->invalid("items.$index.quarantine_location_id", 'Pilih lokasi penahanan barang yang akan diretur.');
                }
                // Menyimpan hasil pemeriksaan; hanya jumlah baik yang dibuatkan alokasi stok di bawah.
                $receipt->inspections()->create([
                    'purchase_order_item_id' => $item->id, 'quantity_good' => $good,
                    'quantity_damaged' => $exceptions['damaged'], 'quantity_wrong' => $exceptions['wrong'],
                    'quantity_missing' => $exceptions['missing'], 'reason' => $reason ?: null,
                    'quarantine_location_id' => ($exceptions['damaged'] + $exceptions['wrong'] > 0) ? $row['quarantine_location_id'] : null,
                ]);
                $item->increment('quantity_reserved', $reserved);
                foreach ($allocations as $allocation) {
                    $location = Location::findOrFail($allocation['location_id']);
                    $expiry = $allocation['expired_date'];
                    $receiptItem = PurchaseReceiptItem::create([
                        'purchase_receipt_id' => $receipt->id, 'purchase_order_item_id' => $item->id,
                        'product_id' => $product->id, 'product_unit_id' => $item->product_unit_id,
                        'location_id' => $location->id, 'quantity_received' => $allocation['quantity'], 'expired_date' => $expiry,
                    ]);
                    // Menambah stok dasar dan nilai persediaan satu kali untuk alokasi barang baik ini.
                    $this->stockService->receiveStockUsingUnit($product, $item->productUnit, $location,
                        (float) $allocation['quantity'], $receipt, $receipt->receipt_date, $order->po_number, $expiry, $receiptItem);
                    if ($allocation['batch_code'] !== null) {
                        $receiptItem->stockBatch()->update(['batch_code' => $allocation['batch_code']]);
                    }
                }
                if ($good > 0) {
                    // Harga master hanya diperbarui dari penerimaan biasa, bukan barang pengganti.
                    if (!$order->root_purchase_order_id && !$item->source_purchase_order_item_id) {
                        app(PurchasePriceService::class)->updateMaster($item, $receipt);
                    }
                    $item->increment('quantity_received', $good);
                    // Barang pengganti memenuhi kewajiban yang sama pada seluruh PO induknya.
                    $sourceId = $item->source_purchase_order_item_id;
                    while ($sourceId) {
                        $source = PurchaseOrderItem::lockForUpdate()->findOrFail($sourceId);
                        if ((float) $source->quantity_reserved + 0.00001 < $good) {
                            $this->invalid('items', 'Jumlah pemenuhan melebihi sisa kewajiban retur/klaim pada PO asal.');
                        }
                        // Menambah pemenuhan dan mengurangi cadangan masalah pada PO sumber tanpa menambah stok lagi.
                        $source->increment('quantity_received', $good);
                        $source->decrement('quantity_reserved', $good);
                        $source->purchaseOrder->refresh()->updateStatus();
                        $sourceId = $source->source_purchase_order_item_id;
                    }
                }
            }
            if (! $processed) {
                $this->invalid('items', 'Isi setidaknya satu jumlah pemeriksaan barang.');
            }
            $order->refresh()->updateStatus();

            return $receipt;
        }, 3);
    }

    // Memeriksa sisa klaim dan menyimpan draf retur beserta rincian masalahnya.
    public function createReturn(array $data, int $userId): PurchaseReturn
    {
        // Mengatur pembuatan retur secara bergantian agar nomor dokumennya tidak bertabrakan.
        return Cache::store('file')->lock('purchase-return-number', 120)->block(10, fn () => DB::transaction(function () use ($data, $userId) {
            $receipt = PurchaseReceipt::findOrFail($data['purchase_receipt_id']);
            $this->lockOrder($receipt->purchase_order_id);
            // Memakai dokumen yang sudah ada jika token, penerimaan, dan pengguna pengirim sesuai.
            if (! empty($data['submission_token']) && $existing = PurchaseReturn::where('submission_token', $data['submission_token'])->first()) {
                if ((int) $existing->purchase_receipt_id !== $receipt->id || (int) $existing->created_by !== $userId) {
                    $this->invalid('submission_token', 'Token retur/klaim tidak sesuai. Muat ulang formulir.');
                }

                return $existing;
            }
            $return = PurchaseReturn::create([
                'return_number' => PurchaseReturn::generateReturnNumber(), 'purchase_receipt_id' => $receipt->id,
                'created_by' => $userId, 'notes' => $data['notes'] ?? null,
                'submission_token' => $data['submission_token'] ?? null,
            ]);
            // Mencatat pasangan pemeriksaan dan jenis masalah agar tidak berulang dalam satu pengajuan.
            $seen = [];
            foreach ($data['items'] as $index => $row) {
                // Mencari pemeriksaan hanya dalam penerimaan yang dipilih dan mengunci barisnya.
                $inspection = $receipt->inspections()->lockForUpdate()->find($row['purchase_inspection_id']);
                if (! $inspection) {
                    $this->invalid("items.$index", 'Hasil pemeriksaan tidak berasal dari penerimaan ini.');
                }
                $key = $inspection->id.':'.$row['kind'];
                if (isset($seen[$key])) {
                    $this->invalid("items.$index", 'Jenis masalah pada barang yang sama tidak boleh berulang.');
                }
                $seen[$key] = true;
                $quantity = round((float) $row['quantity'], 4);
                // Menolak jumlah yang melebihi sisa klaim dengan toleransi kecil untuk angka pecahan.
                if ($quantity > $inspection->claimable($row['kind']) + 0.00001) {
                    $this->invalid("items.$index.quantity", 'Jumlah melebihi hasil pemeriksaan yang belum dibuatkan retur/klaim.');
                }
                if ($quantity > 0) {
                    $return->items()->create(['purchase_inspection_id' => $inspection->id, 'kind' => $row['kind'], 'quantity' => $quantity]);
                }
            }
            // Menolak pengajuan jika semua jumlah nol sehingga tidak ada rincian yang disimpan.
            if (! $return->items()->exists()) {
                $this->invalid('items', 'Pilih setidaknya satu jumlah retur atau klaim.');
            }

            return $return;
        }, 3));
    }

    // Menyetujui draf retur dan membuat PO pengganti dari jumlah yang diajukan.
    public function approve(PurchaseReturn $return, int $userId): PurchaseReturn
    {
        return DB::transaction(function () use ($return, $userId) {
            $order = $this->lockOrder($return->purchaseReceipt->purchase_order_id);
            $return = PurchaseReturn::lockForUpdate()->findOrFail($return->id);
            // Persetujuan ulang mengembalikan dokumen yang sama tanpa membuat PO pengganti baru.
            if ($return->status === 'approved') {
                return $return;
            }
            if ($return->status !== 'draft' || ! $return->items()->exists()) {
                $this->invalid('return', 'Hanya draf retur/klaim yang dapat disetujui.');
            }
            // Menghubungkan seluruh pesanan pengganti ke PO induk yang sama.
            $root = $order->rootOrder ?? $order;
            $sequence = $root->replacementOrders()->count() + 1;
            $number = preg_replace('/^PO(?=[\/-])/', 'PO/R', $root->po_number);
            if ($sequence > 1) {
                $number .= '-'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
            }
            $replacement = PurchaseOrder::create([
                'po_number' => $number, 'supplier_id' => $order->supplier_id, 'order_date' => today(),
                'status' => 'pending', 'created_by' => $userId, 'root_purchase_order_id' => $root->id,
            ]);
            // Menggabungkan masalah dari baris PO yang sama menjadi satu baris pesanan pengganti.
            foreach ($return->items->groupBy(fn ($row) => $row->purchaseInspection->purchase_order_item_id) as $sourceId => $rows) {
                $source = $order->items()->findOrFail($sourceId);
                $replacement->items()->create([
                    'product_id' => $source->product_id, 'product_unit_id' => $source->product_unit_id,
                    'quantity_ordered' => $rows->sum('quantity'), 'buy_price' => $source->buy_price,
                    'source_purchase_order_item_id' => $source->id,
                ]);
            }
            $return->update(['status' => 'approved', 'approved_by' => $userId, 'approved_at' => now(),
                'replacement_purchase_order_id' => $replacement->id]);

            return $return;
        }, 3);
    }

    // Menolak draf retur dan menambahkan alasan penolakan pada catatan lama.
    public function reject(PurchaseReturn $return, string $reason): void
    {
        DB::transaction(function () use ($return, $reason) {
            $this->lockOrder($return->purchaseReceipt->purchase_order_id);
            $return = PurchaseReturn::lockForUpdate()->findOrFail($return->id);
            if ($return->status !== 'draft') {
                $this->invalid('return', 'Hanya draf yang belum disetujui dapat ditolak.');
            }
            // Menambahkan alasan pada catatan lama; status rejected tidak dihitung sebagai klaim aktif.
            $return->update(['status' => 'rejected', 'notes' => trim(($return->notes ?? '')."\nAlasan penolakan: ".$reason)]);
        }, 3);
    }

    // Mencatat waktu dan pengguna penyerahan retur fisik yang telah disetujui.
    public function dispatch(PurchaseReturn $return, int $userId): void
    {
        DB::transaction(function () use ($return, $userId) {
            $this->lockOrder($return->purchaseReceipt->purchase_order_id);
            $return = PurchaseReturn::lockForUpdate()->findOrFail($return->id);
            if ($return->status !== 'approved' || ! $return->items()->whereIn('kind', ['damaged', 'wrong'])->exists()) {
                $this->invalid('return', 'Tidak ada retur fisik yang disetujui untuk diserahkan.');
            }
            // Mencatat penyerahan hanya sekali agar waktu dan pengguna sebelumnya tidak tertimpa.
            if (! $return->dispatched_at) {
                $return->update(['dispatched_at' => now(), 'dispatched_by' => $userId]);
            }
        }, 3);
    }

    // Mengunci PO induk dan PO terkait agar proses pemenuhannya tidak bertabrakan.
    private function lockOrder(int $id): PurchaseOrder
    {
        $order = PurchaseOrder::findOrFail($id);
        PurchaseOrder::whereKey($order->root_purchase_order_id ?? $order->id)->lockForUpdate()->firstOrFail();

        return PurchaseOrder::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    // Menyusun pembagian barang baik berdasarkan lokasi, kedaluwarsa, dan kode batch.
    private function receiptAllocations(array $row, Product $product, int|string $index, float $good): array
    {
        if (array_key_exists('batches', $row)) {
            if (! $product->is_expirable) {
                $this->invalid("items.$index.batches", 'Rincian kedaluwarsa hanya berlaku untuk barang dengan pelacakan kedaluwarsa.');
            }
            if (! empty($row['allocations']) || ! empty($row['expired_date'])) {
                $this->invalid("items.$index.batches", 'Gunakan rincian kedaluwarsa tanpa alokasi atau tanggal kedaluwarsa tunggal.');
            }
            $allocations = [];
            $codes = [];
            foreach ($row['batches'] as $batchIndex => $batch) {
                if ((float) $batch['quantity'] <= 0) {
                    continue;
                }
                if (empty($batch['location_id'])) {
                    $this->invalid("items.$index.batches.$batchIndex.location_id", 'Lokasi wajib diisi untuk setiap rincian kedaluwarsa.');
                }
                if (empty($batch['expired_date'])) {
                    $this->invalid("items.$index.batches.$batchIndex.expired_date", "Tanggal kedaluwarsa wajib diisi pada setiap rincian {$product->name}.");
                }
                $code = trim($batch['batch_code'] ?? '');
                $code = $code === '' ? null : $code;
                if ($code !== null && isset($codes[$code]) && $codes[$code] !== $batch['expired_date']) {
                    $this->invalid("items.$index.batches.$batchIndex.batch_code", 'Kode batch yang sama harus memiliki tanggal kedaluwarsa yang sama.');
                }
                if ($code !== null) {
                    $codes[$code] = $batch['expired_date'];
                }
                // This method handles one PO item (product/unit) within one receipt.
                // Preserve distinct supplier lot codes even when their expiry dates match.
                // Menggabungkan jumlah hanya jika lokasi, tanggal kedaluwarsa, dan kode batch sama.
                $key = json_encode([(int) $batch['location_id'], $batch['expired_date'], $code]);
                $allocations[$key] ??= ['quantity' => 0.0, 'location_id' => $batch['location_id'],
                    'expired_date' => $batch['expired_date'], 'batch_code' => $code];
                $allocations[$key]['quantity'] = round($allocations[$key]['quantity'] + (float) $batch['quantity'], 4);
            }
            if (abs(array_sum(array_column($allocations, 'quantity')) - $good) > 0.00001) {
                $this->invalid("items.$index.batches", 'Total rincian kedaluwarsa harus sama dengan jumlah barang baik yang diterima.');
            }

            return array_values($allocations);
        }

        if ($good > 0 && $product->is_expirable && empty($row['expired_date'])) {
            $this->invalid("items.$index.expired_date", "Tanggal kedaluwarsa wajib diisi untuk {$product->name}.");
        }
        $allocations = collect($row['allocations'] ?? [])->filter(fn ($allocation) => (float) $allocation['quantity'] > 0);
        if (abs((float) $allocations->sum('quantity') - $good) > 0.00001
            || $allocations->pluck('location_id')->duplicates()->isNotEmpty()) {
            $this->invalid("items.$index.allocations", 'Total alokasi harus sama dengan jumlah barang baik, tanpa lokasi ganda.');
        }

        return $allocations->map(fn ($allocation) => [...$allocation,
            'expired_date' => $product->is_expirable ? ($row['expired_date'] ?? null) : null, 'batch_code' => null])->all();
    }

    // Menghentikan proses dengan pesan validasi agar perubahan transaksi dibatalkan.
    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
