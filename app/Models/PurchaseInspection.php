<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class PurchaseInspection extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity_good' => 'decimal:4', 'quantity_damaged' => 'decimal:4',
        'quantity_wrong' => 'decimal:4', 'quantity_missing' => 'decimal:4',
    ];

    // Mengambil dokumen penerimaan asal data ini.
    public function purchaseReceipt()
    {
        return $this->belongsTo(PurchaseReceipt::class);
    }

    // Mengambil baris PO yang menjadi asal rincian atau pemeriksaan ini.
    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    // Mengambil lokasi penahanan barang rusak atau tidak sesuai.
    public function quarantineLocation()
    {
        return $this->belongsTo(Location::class, 'quarantine_location_id');
    }

    // Mengambil rincian pengajuan retur yang menggunakan hasil pemeriksaan ini.
    public function returnItems()
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    // Menyaring pemeriksaan yang masih memiliki sisa masalah untuk diajukan.
    public function scopeWithClaimableIssues(Builder $query): Builder
    {
        return $query->where(function (Builder $issues) {
            foreach (array_keys(PurchaseReturnItem::KINDS) as $kind) {
                $issues->orWhere('quantity_'.$kind, '>', self::claimedQuantityQuery($kind));
            }
        });
    }

    // Menambahkan jumlah klaim aktif tiap jenis masalah ke hasil query pemeriksaan.
    public function scopeWithClaimedQuantities(Builder $query): Builder
    {
        foreach (array_keys(PurchaseReturnItem::KINDS) as $kind) {
            $query->addSelect(['claimed_'.$kind => self::claimedQuantityQuery($kind)]);
        }

        return $query;
    }

    // Menghitung jumlah masalah yang sudah diajukan dalam retur draft atau approved.
    private static function claimedQuantityQuery(string $kind): QueryBuilder
    {
        return DB::table('purchase_return_items')
            ->selectRaw('COALESCE(ROUND(SUM(purchase_return_items.quantity), 4), 0)')
            ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')
            ->whereColumn('purchase_return_items.purchase_inspection_id', 'purchase_inspections.id')
            ->where('purchase_return_items.kind', $kind)
            ->whereIn('purchase_returns.status', ['draft', 'approved']);
    }

    // Menghitung sisa masalah yang boleh diajukan setelah dikurangi klaim aktif.
    public function claimable(string $kind): float
    {
        if (! array_key_exists($kind, PurchaseReturnItem::KINDS)) {
            return 0;
        }
        $claimed = $this->attributes['claimed_'.$kind] ?? $this->returnItems()->where('kind', $kind)
            ->whereHas('purchaseReturn', fn ($query) => $query->whereIn('status', ['draft', 'approved']))->sum('quantity');

        return max(0, round((float) $this->{'quantity_'.$kind} - (float) $claimed, 4));
    }
}
