<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseNumberService
{
    public function next(): string
    {
        $period = now('Asia/Jakarta')->format('Y/m');
        return DB::transaction(function () use ($period) {
            DB::table('expense_number_sequences')->insertOrIgnore(['period' => $period, 'last_number' => 0]);
            $row = DB::table('expense_number_sequences')->where('period', $period)->lockForUpdate()->first();
            $sequence = (int) $row->last_number + 1;
            if ($sequence > 999999) {
                throw ValidationException::withMessages(['expense' => 'Nomor beban bulan ini telah mencapai batas.']);
            }
            DB::table('expense_number_sequences')->where('period', $period)->update(['last_number' => $sequence]);
            return 'BEB/'.$period.'/'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        }, 3);
    }
}
