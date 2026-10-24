<?php

namespace App\Models;

use App\Services\ExpenseNumberService;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    public const CATEGORIES = [
        'salary' => 'Gaji', 'electricity' => 'Listrik', 'water' => 'Air',
        'internet' => 'Internet dan Telepon', 'rent' => 'Sewa',
        'transport' => 'Transportasi', 'supplies' => 'Perlengkapan',
        'cleaning' => 'Kebersihan', 'maintenance' => 'Pemeliharaan',
        'promotion' => 'Promosi', 'other' => 'Beban Operasional Lainnya',
    ];
    public const STATUSES = ['draft' => 'Draft', 'confirmed' => 'Dikonfirmasi', 'void' => 'Dibatalkan'];

    protected $fillable = [
        'recognized_date', 'category', 'description', 'amount', 'status',
        'paid_date', 'payment_method', 'proof_path', 'created_by',
        'confirmed_by', 'confirmed_at', 'void_reason', 'voided_at', 'version', 'submission_token',
    ];

    protected $casts = [
        'recognized_date' => 'date', 'paid_date' => 'date', 'amount' => 'decimal:2',
        'confirmed_at' => 'datetime', 'voided_at' => 'datetime', 'version' => 'integer',
    ];

    public function getNumberAttribute(): string
    {
        return (string) $this->expense_number;
    }

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

    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
    public function confirmedBy() { return $this->belongsTo(User::class, 'confirmed_by'); }
}
