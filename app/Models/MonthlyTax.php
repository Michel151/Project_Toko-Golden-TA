<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyTax extends Model
{
    protected $attributes = ['status' => 'draft', 'version' => 1];
    protected $fillable = ['month', 'amount', 'description', 'status', 'proof_path', 'version', 'created_by', 'confirmed_by', 'confirmed_at'];
    protected $casts = ['amount' => 'decimal:2', 'version' => 'integer', 'confirmed_at' => 'datetime'];

    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
    public function confirmedBy() { return $this->belongsTo(User::class, 'confirmed_by'); }
    public function revisions() { return $this->hasMany(MonthlyTaxRevision::class); }
}
