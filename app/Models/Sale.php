<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $fillable = [
        'sale_number',
        'customer_name',
        'sale_date',
        'sale_time',
        'total_amount',
        'payment_method',
        'amount_paid',
        'change_amount',
        'status',
        'hold_until',
        'created_by',
    ];

    protected $casts = [
        'sale_date'     => 'date',
        'total_amount'  => 'decimal:2',
        'amount_paid'   => 'decimal:2',
        'change_amount' => 'decimal:2',
        'hold_until' => 'datetime',
    ];

    // Mengambil pengguna yang membuat transaksi penjualan.
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Memeriksa apakah pengguna boleh mengakses transaksi ini berdasarkan kepemilikan kasir.
    public function isAccessibleBy(User $user): bool
    {
        return ! $user->isKasir() || (int) $this->created_by === (int) $user->id;
    }

    // Menyiapkan teks jam transaksi atau keterangan jika jam belum tercatat.
    public function getTransactionTimeLabelAttribute(): string
    {
        return $this->sale_time ? $this->sale_time.' WIB' : 'Jam tidak tercatat';
    }

    // Mengambil seluruh rincian barang pada transaksi penjualan ini.
    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    // Membatasi query penjualan ke transaksi yang sudah lunas.
    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    // Membuat nomor invoice berdasarkan bulan dan urutan penjualan terakhir.
    public static function generateSaleNumber(?\DateTimeInterface $issuedAt = null): string
    {
        $date = $issuedAt ? Carbon::instance($issuedAt)->setTimezone('Asia/Jakarta') : now('Asia/Jakarta');
        $prefix = 'INV/'.$date->format('Y/m').'/';

        $current = static::where('sale_number', 'like', "{$prefix}%")
            ->selectRaw('MAX(CAST(SUBSTR(sale_number, 13) AS DECIMAL(20,0))) AS sequence')->value('sequence');
        $seq = (int) $current + 1;
        return $prefix . str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
