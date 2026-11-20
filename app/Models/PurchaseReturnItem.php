<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturnItem extends Model
{
    public const KINDS = ['damaged' => 'Rusak', 'wrong' => 'Tidak sesuai', 'missing' => 'Kekurangan Kiriman'];

    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'decimal:4'];

    // Mengambil dokumen retur pemilik rincian ini.
    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    // Mengambil hasil pemeriksaan yang menjadi dasar pengajuan ini.
    public function purchaseInspection()
    {
        return $this->belongsTo(PurchaseInspection::class);
    }
}
