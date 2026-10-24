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

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items()
    {
        return $this->hasMany(StockOpnameItem::class);
    }

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
