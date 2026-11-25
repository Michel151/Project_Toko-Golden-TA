<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class StockOpname extends Model
{
    protected $fillable = [
        'opname_number',
        'location_id',
        'opname_date',
        'status',
        'created_by',
        'participants',
    ];

    protected $casts = [
        'opname_date' => 'date',
        'participants' => 'array',
    ];

    // Mengambil lokasi tempat opname dilakukan.
    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    // Mengambil pengguna yang membuat dokumen opname.
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Mengambil daftar barang dalam dokumen opname.
    public function items()
    {
        return $this->hasMany(StockOpnameItem::class);
    }

    // Mencari opname yang melibatkan petugas dengan ID yang dipilih.
    public function scopeForParticipant(\Illuminate\Database\Eloquent\Builder $query, int $userId): void
    {
        $query->where(function ($query) use ($userId) {
            if ($query->getConnection()->getDriverName() === 'sqlite') {
                $query->whereRaw("EXISTS (SELECT 1 FROM json_each(stock_opnames.participants) AS participant WHERE json_extract(participant.value, '$.user_id') = ?)", [$userId]);
            } else {
                $query->whereJsonContains('participants', ['user_id' => $userId]);
            }
            // Untuk dokumen lama tanpa daftar petugas, gunakan pembuat dokumen sebagai petugas.
            $query->orWhere(fn ($legacy) => $legacy->where(fn ($empty) => $empty->whereNull('participants')->orWhereJsonLength('participants', 0))
                ->where('created_by', $userId));
        });
    }

    // Membuat nomor opname berdasarkan tahun, bulan, dan urutan berikutnya.
    public static function generateOpnameNumber(?\DateTimeInterface $issuedAt = null): string
    {
        $date = $issuedAt ? Carbon::instance($issuedAt)->setTimezone('Asia/Jakarta') : now('Asia/Jakarta');
        $prefix = 'OPN/'.$date->format('Y/m').'/';
        $last = static::where('opname_number', 'like', "{$prefix}%")
            ->selectRaw('MAX(CAST(SUBSTR(opname_number, 13) AS DECIMAL(20,0))) AS sequence')->value('sequence');
        $seq = (int) $last + 1;
        return $prefix . str_pad($seq, 6, '0', STR_PAD_LEFT);
    }
}
