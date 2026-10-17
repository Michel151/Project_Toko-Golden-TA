<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleDraft extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'version' => 'integer', 'client_updated_at' => 'integer', 'user_id' => 'integer', 'sale_id' => 'integer'];
    }
}
