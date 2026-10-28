<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MonthlyDateRange
{
    public static function fromRequest(Request $request): array
    {
        $today = now('Asia/Jakarta');
        $from = $request->input('from', $today->copy()->startOfMonth()->toDateString());
        $to = $request->input('to', $today->toDateString());

        // Explicitly empty dates retain the option to view an unbounded range.
        return Validator::make(['from'=>$from, 'to'=>$to], [
            'from'=>['nullable', 'date_format:Y-m-d'],
            'to'=>array_merge(['nullable', 'date_format:Y-m-d'], $from ? ['after_or_equal:from'] : []),
        ])->validate();
    }
}
