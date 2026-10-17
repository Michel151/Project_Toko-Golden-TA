<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImportRun extends Model
{
    protected $fillable=['token','user_id','status','payload','result','expires_at'];
    protected $casts=['payload'=>'encrypted:array','result'=>'array','expires_at'=>'datetime'];
}
