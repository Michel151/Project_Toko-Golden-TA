<?php

namespace App\Services;

use App\Models\SaleDraft;
use Illuminate\Http\Request;

class SaleDraftService
{
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
        if ($draft->version !== $request->integer('draft_version')) {
            throw new \RuntimeException('Draft berubah di tab lain. Muat ulang dan periksa keranjang sebelum menyimpan.');
        }
        $draft->update(['status' => 'consumed', 'payload' => null, 'version' => $draft->version + 1]);
    }
}
