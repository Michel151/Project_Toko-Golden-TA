<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyTaxRevision extends Model
{
    public $timestamps = false;
    protected $fillable = ['snapshot', 'action', 'reason', 'created_by', 'created_at'];
    protected $casts = ['snapshot' => 'array', 'created_at' => 'datetime'];
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
}
