<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    // $fillable mengizinkan create() dan update() mengisi nama kategori dari data form.
    protected $fillable = ['name'];

    // Menemukan barang yang masuk ke kategori ini.
    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
