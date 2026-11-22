<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleDraft extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $appends = ['expires_at'];

    // Menentukan batas 18.00 WIB berikutnya berdasarkan waktu pembuatan draf.
    public static function deadline($startedAt = null): \Carbon\Carbon
    {
        $startedAt = $startedAt ? \Carbon\Carbon::parse($startedAt)->setTimezone('Asia/Jakarta') : now('Asia/Jakarta');
        $deadline = $startedAt->copy()->setTime(18, 0);
        return $startedAt->gte($deadline) ? $deadline->addDay() : $deadline;
    }

    // Menambahkan batas kedaluwarsa hasil perhitungan saat model dibaca sebagai JSON.
    public function getExpiresAtAttribute(): ?string
    {
        return $this->created_at ? static::deadline($this->created_at)->toIso8601String() : null;
    }

    // Memeriksa apakah batas waktu draf sudah tercapai.
    public function isExpired(): bool
    {
        return $this->created_at && static::deadline($this->created_at)->lte(now('Asia/Jakarta'));
    }

    // Mengubah payload menjadi array dan nilai ID serta versi menjadi integer saat dibaca.
    protected function casts(): array
    {
        return ['payload' => 'array', 'version' => 'integer', 'client_updated_at' => 'integer', 'user_id' => 'integer', 'sale_id' => 'integer'];
    }
}
