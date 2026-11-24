<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MonthlyDateRange
{
    // Menyiapkan dan memvalidasi periode; tanggal yang sengaja dikosongkan tidak diberi batas bawaan.
    public static function fromRequest(Request $request): array
    {
        $today = now('Asia/Jakarta');
        $from = $request->input('from', $today->copy()->startOfMonth()->toDateString());
        $to = $request->input('to', $today->toDateString());

        // Tanggal kosong mempertahankan pilihan rentang tanpa batas tanggal tersebut.
        return Validator::make(['from'=>$from, 'to'=>$to], [
            'from'=>['nullable', 'date_format:Y-m-d'],
            'to'=>array_merge(['nullable', 'date_format:Y-m-d'], $from ? ['after_or_equal:from'] : []),
        ])->validate();
    }
}
