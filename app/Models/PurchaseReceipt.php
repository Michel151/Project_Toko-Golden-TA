<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReceipt extends Model
{
    // Mengambil seluruh hasil pemeriksaan baik, rusak, tidak sesuai, dan kurang.
    public function inspections() { return $this->hasMany(PurchaseInspection::class); }
    // Mengambil pengajuan retur atau klaim yang berasal dari penerimaan ini.
    public function returns() { return $this->hasMany(PurchaseReturn::class); }
    protected $fillable = [
        'submission_token',
        'purchase_order_id',
        'receipt_date',
        'received_by',
        'notes',
    ];

    protected $casts = [
        'receipt_date' => 'date',
    ];

    // Mengambil PO yang menjadi asal data ini.
    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    // Mengambil pengguna yang mencatat penerimaan.
    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    // Mengambil alokasi barang baik yang diterima ke lokasi stok.
    public function items()
    {
        return $this->hasMany(PurchaseReceiptItem::class);
    }
}
