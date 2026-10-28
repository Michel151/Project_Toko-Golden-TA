<?php

namespace App\Services;

use App\Models\{PurchaseOrder, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderLifecycleService
{
    public function confirm(PurchaseOrder $order, User $user): void
    {
        abort_unless($user->canAccessRoute('purchase.orders.confirm'), 403);
        DB::transaction(function () use ($order, $user) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);
            if ($order->status === 'pending' && $order->confirmed_at) return;
            abort_unless($order->canModify(), 409, 'Hanya PO draf yang dapat dikonfirmasi.');
            $items = $order->items()->with(['product', 'productUnit'])->get();
            if ($items->isEmpty()) throw ValidationException::withMessages(['items'=>'Tambahkan minimal satu barang sebelum mengonfirmasi PO.']);
            foreach ($items as $item) {
                if (!$item->product?->is_active || !$item->productUnit
                    || (int) $item->productUnit->product_id !== (int) $item->product_id
                    || $item->productUnit->conversion_to_base <= 0
                    || $item->quantity_ordered <= 0 || $item->buy_price < 0
                    || !DB::table('product_supplier')->where('supplier_id', $order->supplier_id)->where('product_id', $item->product_id)->exists()) {
                    throw ValidationException::withMessages(['items'=>'Barang, satuan, atau hubungan supplier sudah berubah. Periksa kembali draf PO.']);
                }
            }
            $order->update(['status'=>'pending', 'confirmed_by'=>$user->id, 'confirmed_at'=>now()]);
        });
    }

    public function cancel(PurchaseOrder $order, User $user, string $reason): void
    {
        abort_unless($user->canAccessRoute('purchase.orders.cancel'), 403);
        DB::transaction(function () use ($order, $user, $reason) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);
            if ($order->status === 'cancelled') return;
            abort_unless($order->canCancel(), 409, 'PO sudah memiliki penerimaan atau tidak dapat dibatalkan.');
            abort_if($order->status !== 'draft' && $user->role !== 'owner', 403, 'Pembatalan PO terkonfirmasi hanya dapat dilakukan owner.');
            $order->update(['status'=>'cancelled', 'cancelled_by'=>$user->id, 'cancelled_at'=>now(), 'cancellation_reason'=>$reason]);
        });
    }

    public function closeRemainder(PurchaseOrder $order, User $user, string $reason): void
    {
        abort_unless($user->role === 'owner', 403);
        DB::transaction(function () use ($order, $user, $reason) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);
            if ($order->status === 'closed') return;
            abort_unless($order->canCloseRemainder(), 409, 'Sisa pesanan hanya dapat ditutup setelah penerimaan sebagian dan seluruh retur/klaim diselesaikan.');
            $order->update(['status'=>'closed', 'closed_by'=>$user->id, 'closed_at'=>now(), 'closure_reason'=>$reason]);
        });
    }
}
