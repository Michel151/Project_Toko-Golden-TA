<?php

namespace App\Models;

use App\Services\ExpenseNumberService;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    // Pilihan kategori operasional untuk form, filter, dan nama yang ditampilkan.
    public const CATEGORIES = [
        'salary' => 'Gaji', 'electricity' => 'Listrik', 'water' => 'Air',
        'internet' => 'Internet dan Telepon', 'rent' => 'Sewa',
        'transport' => 'Transportasi', 'supplies' => 'Perlengkapan',
        'cleaning' => 'Kebersihan', 'maintenance' => 'Pemeliharaan',
        'promotion' => 'Promosi', 'other' => 'Beban Operasional Lainnya',
    ];
    public const STATUSES = ['draft' => 'Draft', 'confirmed' => 'Dikonfirmasi', 'void' => 'Dibatalkan'];

    // Kolom yang boleh diisi melalui create() atau fill().
    protected $fillable = [
        'recognized_date', 'category', 'description', 'amount', 'status',
        'paid_date', 'payment_method', 'proof_path', 'created_by',
        'confirmed_by', 'confirmed_at', 'void_reason', 'voided_at', 'version', 'submission_token',
    ];

    // Mengubah tanggal, nominal, dan versi ke tipe yang dipakai program.
    protected $casts = [
        'recognized_date' => 'date', 'paid_date' => 'date', 'amount' => 'decimal:2',
        'confirmed_at' => 'datetime', 'voided_at' => 'datetime', 'version' => 'integer',
    ];

    // Memungkinkan nomor beban dibaca melalui $expense->number.
    public function getNumberAttribute(): string
    {
        return (string) $this->expense_number;
    }

    // Membuat nomor saat penambahan dan melarang perubahan nomor yang sudah diterbitkan.
    protected static function booted(): void
    {
        static::creating(function (Expense $expense) {
            $expense->expense_number = app(ExpenseNumberService::class)->next();
        });
        static::updating(function (Expense $expense) {
            if ($expense->isDirty('expense_number')) {
                throw new \LogicException('Nomor beban tidak dapat diubah.');
            }
        });
    }

    // Mengambil pengguna yang membuat beban.
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
    // Mengambil pengguna yang mengonfirmasi beban.
    public function confirmedBy() { return $this->belongsTo(User::class, 'confirmed_by'); }
}
