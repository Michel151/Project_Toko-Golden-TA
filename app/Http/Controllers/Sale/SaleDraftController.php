<?php

namespace App\Http\Controllers\Sale;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleDraft;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleDraftController extends Controller
{
    private function authorizeContext(Request $request, ?int $saleId): void
    {
        abort_unless($request->user()->hasPermission($saleId ? 'sale.sales.edit' : 'sale.sales.create'), 403);
        if ($saleId) {
            $sale = Sale::findOrFail($saleId);
            abort_unless($request->user()->isOwner() || $sale->created_by === $request->user()->id, 403);
            abort_unless($sale->status === 'pending' && $sale->hold_until->gt(now('Asia/Jakarta')), 409, 'Transaksi sudah ditutup.');
        }
    }

    public function index(Request $request)
    {
        $request->validate(['sale_id' => 'nullable|integer|min:1']);
        $saleId = $request->integer('sale_id') ?: null;
        $this->authorizeContext($request, $saleId);

        app(\App\Services\SaleDraftService::class)->expireOverdue($request->user()->id);

        return response()->json(SaleDraft::where('user_id', $request->user()->id)
            ->where('sale_id', $saleId)->orderByDesc('updated_at')->get())
            ->header('Cache-Control', 'no-store')
            ->header('X-Draft-Server-Time', now('Asia/Jakarta')->toIso8601String());
    }

    public function update(Request $request, string $id)
    {
        abort_unless(\Illuminate\Support\Str::isUuid($id), 404);
        $data = $request->validate([
            'sale_id' => 'nullable|integer|min:1',
            'version' => 'required|integer|min:0',
            'status' => 'required|in:active,discarded',
            'client_updated_at' => 'required|integer|min:0',
            'expires_at' => 'nullable|date',
            'payload' => 'required_if:status,active|nullable|array:sale_date,payment_method,sale_status,customer_name,amount_paid,default_location_id,items',
            'payload.sale_date' => 'nullable|string|max:20',
            'payload.payment_method' => 'nullable|in:tunai,transfer',
            'payload.sale_status' => 'nullable|in:paid,pending',
            'payload.customer_name' => 'nullable|string|max:150',
            'payload.amount_paid' => 'nullable|numeric|min:0',
            'payload.default_location_id' => 'nullable|integer',
            'payload.items' => 'required_if:status,active|array|max:200',
            'payload.items.*' => 'array:product_id,product_unit_id,product_name,quantity,unit_price,source_mode,sources',
            'payload.items.*.product_id' => 'nullable|integer',
            'payload.items.*.product_unit_id' => 'nullable|integer',
            'payload.items.*.product_name' => 'nullable|string|max:255',
            'payload.items.*.quantity' => 'nullable|numeric',
            'payload.items.*.unit_price' => 'nullable|numeric',
            'payload.items.*.source_mode' => 'nullable|string|max:30',
            'payload.items.*.sources' => 'array|max:100',
            'payload.items.*.sources.*' => 'array:location_id,quantity',
            'payload.items.*.sources.*.location_id' => 'nullable|integer',
            'payload.items.*.sources.*.quantity' => 'nullable|numeric',
        ]);
        $saleId = isset($data['sale_id']) ? (int) $data['sale_id'] : null;
        $this->authorizeContext($request, $saleId);

        app(\App\Services\SaleDraftService::class)->expireOverdue($request->user()->id);
        if (!empty($data['expires_at']) && \Carbon\Carbon::parse($data['expires_at'])->lte(now('Asia/Jakarta'))) {
            return response()->json(['message'=>'Draf sudah kedaluwarsa pada batas harian pukul 18.00 WIB.'], 410)
                ->header('X-Draft-Server-Time', now('Asia/Jakarta')->toIso8601String());
        }

        return DB::transaction(function () use ($request, $id, $data, $saleId) {
            // A closed row is kept as a tombstone so delayed autosaves cannot revive it.
            $draft = SaleDraft::firstOrCreate(['id' => $id], ['user_id' => $request->user()->id, 'sale_id' => $saleId]);
            $draft = SaleDraft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            abort_unless($draft->user_id === $request->user()->id && $draft->sale_id === $saleId, 403);
            if ($draft->status === 'active' && $draft->isExpired()) {
                $draft->update(['status'=>'expired', 'payload'=>null, 'version'=>$draft->version + 1]);
            }
            if ($draft->status !== 'active' || $draft->version !== $data['version']) {
                return response()->json(['message' => 'Draft berubah atau sudah ditutup.', 'draft' => $draft], 409);
            }
            $draft->update([
                'status' => $data['status'], 'version' => $draft->version + 1,
                'payload' => $data['status'] === 'active' ? $data['payload'] : null,
                'client_updated_at' => $data['client_updated_at'],
            ]);

            return response()->json($draft);
        });
    }
}
