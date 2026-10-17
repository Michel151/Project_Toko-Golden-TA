<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PurchaseInspection extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity_good' => 'decimal:4', 'quantity_damaged' => 'decimal:4',
        'quantity_wrong' => 'decimal:4', 'quantity_missing' => 'decimal:4',
    ];

    public function purchaseReceipt()
    {
        return $this->belongsTo(PurchaseReceipt::class);
    }

    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function quarantineLocation()
    {
        return $this->belongsTo(Location::class, 'quarantine_location_id');
    }

    public function returnItems()
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function scopeWithClaimableIssues(Builder $query): Builder
    {
        return $query->where(function (Builder $issues) {
            foreach (array_keys(PurchaseReturnItem::KINDS) as $kind) {
                $issues->orWhere('quantity_'.$kind, '>', function ($claimed) use ($kind) {
                    $claimed->selectRaw('COALESCE(ROUND(SUM(purchase_return_items.quantity), 4), 0)')
                        ->from('purchase_return_items')
                        ->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')
                        ->whereColumn('purchase_return_items.purchase_inspection_id', 'purchase_inspections.id')
                        ->where('purchase_return_items.kind', $kind)
                        ->whereIn('purchase_returns.status', ['draft', 'approved']);
                });
            }
        });
    }

    public function claimable(string $kind): float
    {
        if (! array_key_exists($kind, PurchaseReturnItem::KINDS)) {
            return 0;
        }
        $claimed = $this->returnItems()->where('kind', $kind)
            ->whereHas('purchaseReturn', fn ($query) => $query->whereIn('status', ['draft', 'approved']))->sum('quantity');

        return max(0, round((float) $this->{'quantity_'.$kind} - (float) $claimed, 4));
    }
}
