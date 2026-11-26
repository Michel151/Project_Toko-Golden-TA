<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyTaxRevision extends Model
{
    // Waktu riwayat diisi oleh controller; model tidak memakai updated_at.
    public $timestamps = false;
    protected $fillable = ['snapshot', 'action', 'reason', 'created_by', 'created_at'];
    // Membaca salinan data JSON sebagai array dan waktu tindakan sebagai objek tanggal.
    protected $casts = ['snapshot' => 'array', 'created_at' => 'datetime'];
    // Mengambil pengguna yang melakukan tindakan pada riwayat ini.
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
}
