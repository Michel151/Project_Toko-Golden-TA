<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseInspection;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReturnItem;
use App\Models\StockMovement;
use App\Support\DisplayNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StockCardPurchaseHistory
{
    // Melengkapi mutasi penerimaan dan mengembalikan baris informasi tambahan untuk kartu stok.
    public function rows(Product $product, Collection $movements, string $from, string $to, ?int $locationId): Collection
    {
        $receiptReference = (new PurchaseReceipt)->getMorphClass();
        // Mengumpulkan mutasi berdasarkan dokumen penerimaannya.
        $byReceipt = $movements->filter(fn ($row) => $row->movement_type === 'purchase' && $row->reference_type === $receiptReference)
            ->groupBy('reference_id');

        // Membatasi pemeriksaan pada barang yang sedang dibuka kartu stoknya.
        $inspections = fn ($query) => $query->whereRelation('purchaseOrderItem', 'product_id', $product->id);
        // Mencari penerimaan yang memiliki mutasi terkait atau hasil pemeriksaan bermasalah.
        $receipts = PurchaseReceipt::query()->whereDate('receipt_date', '>=', $from)->whereDate('receipt_date', '<=', $to)
            ->where(function ($query) use ($byReceipt, $inspections) {
                $query->whereKey($byReceipt->keys()->all())->orWhereHas('inspections', function ($query) use ($inspections) {
                    $inspections($query)->where(fn ($query) => $query->where('quantity_damaged', '>', 0)
                        ->orWhere('quantity_wrong', '>', 0)->orWhere('quantity_missing', '>', 0));
                });
            })
            ->with(['purchaseOrder:id,po_number,root_purchase_order_id', 'receivedBy', 'inspections' => fn ($query) =>
                $inspections($query)->with(['purchaseOrderItem.productUnit.unit', 'quarantineLocation'])])
            ->get();

        $notes = collect();
        foreach ($receipts as $receipt) {
            $rows = $byReceipt->get($receipt->id, collect());
            $replacement = $receipt->purchaseOrder->root_purchase_order_id !== null;
            $summary = $this->receiptSummary($receipt->inspections, $product);

            // Memberi label khusus pada mutasi dari PO pengganti.
            if ($replacement) {
                $rows->each(fn ($row) => $row->card_type_label = 'Penerimaan Pengganti');
            }
            if ($summary === null) {
                continue;
            }
            // Jika mutasi sudah ada, tempelkan ringkasan pada catatan mutasi pertama.
            if ($first = $rows->first()) {
                $first->notes = trim(implode(' ', array_filter([$first->notes, $summary])));
                continue;
            }

            // Tanpa mutasi masuk, cari lokasi karantina untuk baris informasi baru.
            $locations = $receipt->inspections->pluck('quarantineLocation')->filter()->unique('id');
            $location = $locationId ? $locations->firstWhere('id', $locationId) : ($locations->count() === 1 ? $locations->first() : null);
            // Lewati baris tambahan jika lokasi karantina tidak sesuai filter.
            if ($locationId && ! $location) {
                continue;
            }
            $notes->push($this->note($receipt->id, 'purchase', $receipt->receipt_date, $receipt->created_at,
                $receipt->purchaseOrder->po_number, $summary, $location, $receipt->receivedBy,
                $replacement ? 'Pemeriksaan Pengganti' : 'Pemeriksaan Penerimaan', $receipt));
        }

        return $notes->concat($this->dispatchRows($product, $from, $to, $locationId));
    }

    // Menyusun ringkasan jumlah tiba, jumlah baik, dan masalah pemeriksaan; tanpa masalah hasilnya null.
    private function receiptSummary(Collection $inspections, Product $product): ?string
    {
        $issues = collect(['damaged' => '%s rusak', 'wrong' => '%s tidak sesuai', 'missing' => 'kurang %s'])
            ->flatMap(function ($label, $kind) use ($inspections, $product) {
                $rows = $inspections->filter(fn ($row) => $row->{'quantity_'.$kind} > 0);

                // Masalah dikelompokkan per lokasi karantina; barang kurang tidak memiliki lokasi fisik.
                return $rows->groupBy(fn ($row) => $kind === 'missing' ? null : $row->quarantine_location_id)
                    ->map(function ($group) use ($product, $kind, $label) {
                        $quantity = $this->quantities($group, $product, fn ($row) => $row->{'quantity_'.$kind});
                        $location = $kind === 'missing' ? null : $group->first()->quarantineLocation?->name;

                        return sprintf($label, $quantity).($location ? ', ditahan di '.$location : '');
                    })->values();
            });
        if ($issues->isEmpty()) {
            return null;
        }

        // Jumlah tiba mencakup baik, rusak, dan tidak sesuai; barang kurang belum tiba.
        $arrived = $this->quantities($inspections, $product,
            fn ($row) => (float) $row->quantity_good + (float) $row->quantity_damaged + (float) $row->quantity_wrong);
        $good = $this->quantities($inspections, $product, fn ($row) => $row->quantity_good);

        return 'Total penerimaan: '.$arrived.' tiba, '.$good.' lolos pemeriksaan. '.ucfirst($issues->implode('; ')).'.';
    }

    // Membuat informasi penyerahan retur rusak atau tidak sesuai pada periode dan lokasi terpilih.
    private function dispatchRows(Product $product, string $from, string $to, ?int $locationId): Collection
    {
        return PurchaseReturnItem::query()->whereIn('kind', ['damaged', 'wrong'])
            ->whereHas('purchaseReturn', fn ($query) => $query->whereBetween('dispatched_at', [
                Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay(),
            ]))
            ->whereHas('purchaseInspection', fn ($query) => $query->whereRelation('purchaseOrderItem', 'product_id', $product->id)
                ->when($locationId, fn ($query) => $query->where('quarantine_location_id', $locationId)))
            ->with(['purchaseReturn.dispatchedBy', 'purchaseInspection.purchaseOrderItem.productUnit.unit', 'purchaseInspection.quarantineLocation'])
            ->get()
            // Satu dokumen retur dan satu lokasi karantina menjadi satu baris informasi.
            ->groupBy(fn ($row) => $row->purchase_return_id.':'.$row->purchaseInspection->quarantine_location_id)
            ->map(function ($items) use ($product) {
                $first = $items->first();
                $return = $first->purchaseReturn;
                $quantity = $this->quantities($items, $product, fn ($item) => $item->quantity);

                return $this->note($first->id, 'return_dispatch', $return->dispatched_at, $return->dispatched_at,
                    $return->return_number, $quantity.' diserahkan ke supplier.',
                    $first->purchaseInspection->quarantineLocation, $return->dispatchedBy, 'Penyerahan Retur', $return);
            })->values();
    }

    // Menjumlahkan per nama satuan dan menghasilkan teks, misalnya 2 Dus + 3 Pcs.
    private function quantities(Collection $rows, Product $product, callable $quantity): string
    {
        $fallbackUnit = $product->buyUnit?->name;

        return $rows->groupBy(function ($row) use ($fallbackUnit) {
            // Data bisa berupa pemeriksaan langsung atau item retur yang memiliki pemeriksaan.
            $inspection = $row instanceof PurchaseInspection ? $row : $row->purchaseInspection;

            return $inspection->purchaseOrderItem->productUnit?->unit?->name ?? $fallbackUnit;
        })->map(fn ($group, $unit) => trim(DisplayNumber::quantity($group->sum($quantity)).' '.$unit))->implode(' + ');
    }

    // Membuat objek baris informasi berjumlah nol untuk tampilan, tanpa menyimpan ke database.
    private function note(int $id, string $type, $date, $time, string $reference, string $description, $location, $user, string $label, Model $document): StockMovement
    {
        $row = new StockMovement([
            'movement_type' => $type, 'quantity' => 0, 'movement_date' => $date->toDateString(),
            'reference_number' => $reference, 'reference_type' => $document->getMorphClass(),
            'reference_id' => $document->getKey(), 'notes' => $description,
        ]);
        $row->id = $id;
        $row->created_at = $time;
        $row->card_type_label = $label;
        // Memasang objek lokasi dan petugas agar bisa langsung dibaca oleh view.
        $row->setRelation('location', $location);
        $row->setRelation('createdBy', $user);

        return $row;
    }
}
