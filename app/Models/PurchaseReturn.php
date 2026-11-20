<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class PurchaseReturn extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['approved_at' => 'datetime', 'dispatched_at' => 'datetime'];

    // Membuat nomor retur berdasarkan bulan dan urutan terakhir.
    public static function generateReturnNumber(?\DateTimeInterface $issuedAt = null): string
    {
        $date = $issuedAt ? Carbon::instance($issuedAt)->setTimezone('Asia/Jakarta') : now('Asia/Jakarta');
        $prefix = 'RT/'.$date->format('Y/m').'/';
        $last = static::where('return_number', 'like', "{$prefix}%")
            ->selectRaw('MAX(CAST(SUBSTR(return_number, 12) AS DECIMAL(20,0))) AS sequence')->value('sequence');
        $seq = (int) $last + 1;
        return $prefix.str_pad($seq, 6, '0', STR_PAD_LEFT);
    }

    // Mengambil dokumen penerimaan asal data ini.
    public function purchaseReceipt()
    {
        return $this->belongsTo(PurchaseReceipt::class);
    }

    // Mengambil rincian jenis masalah dan jumlah yang diajukan pada retur ini.
    public function items()
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    // Mengambil PO pengganti yang diterbitkan dari persetujuan retur ini.
    public function replacementOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'replacement_purchase_order_id');
    }

    // Mengambil pengguna yang membuat pengajuan retur.
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Mengambil pengguna yang menyetujui pengajuan retur.
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Mengambil pengguna yang mencatat penyerahan barang retur ke supplier.
    public function dispatchedBy()
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }
}
