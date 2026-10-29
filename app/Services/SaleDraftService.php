<?php

namespace App\Services;

use App\Models\SaleDraft;
use Illuminate\Http\Request;

class SaleDraftService
{
    public function expireOverdue(?int $userId = null): int
    {
        $cutoff = now('Asia/Jakarta')->setTime(18, 0);
        if ($cutoff->gt(now('Asia/Jakarta'))) $cutoff->subDay();
        return SaleDraft::where('status', 'active')
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->where('created_at', '<', $cutoff->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'))
            ->update(['status' => 'expired', 'payload' => null, 'version' => \Illuminate\Support\Facades\DB::raw('version + 1')]);
    }

    // Called inside the sale transaction; a failed sale also rolls back draft closure.
    public function consume(Request $request, ?int $saleId = null): void
    {
        $id = $request->input('draft_id');
        if (! $id) {
            return;
        }
        if (! \Illuminate\Support\Str::isUuid($id)) {
            throw new \RuntimeException('Identitas draft tidak valid.');
        }
        $draft = SaleDraft::firstOrCreate(['id' => $id], ['user_id' => $request->user()->id, 'sale_id' => $saleId]);
        $draft = SaleDraft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
        if ($draft->user_id !== $request->user()->id || $draft->sale_id !== $saleId) {
            throw new \RuntimeException('Draft tidak sesuai dengan kasir atau transaksi ini.');
        }
        if ($draft->status !== 'active') {
            throw new \RuntimeException('Draft ini sudah disimpan atau dihapus. Muat ulang halaman sebelum melanjutkan.');
        }
        if ($draft->isExpired()) {
            throw new \RuntimeException('Draf kedaluwarsa pada batas harian pukul 18.00 WIB. Mulai keranjang baru.');
        }
        if ($draft->version !== $request->integer('draft_version')) {
            throw new \RuntimeException('Draft berubah di tab lain. Muat ulang dan periksa keranjang sebelum menyimpan.');
        }
        $draft->update(['status' => 'consumed', 'payload' => null, 'version' => $draft->version + 1]);
    }
}
