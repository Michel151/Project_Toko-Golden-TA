<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class StockTransfer extends Model
{
    protected $fillable = [
        'transfer_number',
        'product_id',
        'product_unit_id',
        'from_location_id',
        'to_location_id',
        'quantity',
        'transfer_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quantity'      => 'decimal:4',
        'transfer_date' => 'date',
    ];

    // Menghubungkan barang pertama yang disimpan pada kolom format lama.
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Mengambil seluruh rincian barang dalam satu dokumen transfer.
    public function items()
    {
        return $this->hasMany(StockTransferItem::class);
    }

    // Menghubungkan satuan barang pertama pada kolom format lama.
    public function productUnit()
    {
        return $this->belongsTo(ProductUnit::class);
    }

    // Mengambil lokasi asal melalui from_location_id.
    public function fromLocation()
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    // Mengambil lokasi tujuan melalui to_location_id.
    public function toLocation()
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    // Mengambil pengguna yang mencatat transfer.
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Membuat nomor transfer dengan tahun, bulan, dan urutan berikutnya.
    public static function generateTransferNumber(?\DateTimeInterface $issuedAt = null): string
    {
        $date = $issuedAt ? Carbon::instance($issuedAt)->setTimezone('Asia/Jakarta') : now('Asia/Jakarta');
        $prefix = 'TRF/'.$date->format('Y/m').'/';
        $last = static::where('transfer_number', 'like', "{$prefix}%")
            ->selectRaw('MAX(CAST(SUBSTR(transfer_number, 13) AS DECIMAL(20,0))) AS sequence')->value('sequence');
        $seq = (int) $last + 1;
        return $prefix . str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
