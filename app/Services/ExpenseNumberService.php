<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseNumberService
{
    // Menghasilkan nomor beban baru berdasarkan periode saat ini.
    public function next(): string
    {
        $period = now('Asia/Jakarta')->format('Y/m');
        return DB::transaction(function () use ($period) {
            // Membuat urutan bulan baru tanpa mereset urutan bulan yang sudah ada.
            DB::table('expense_number_sequences')->insertOrIgnore(['period' => $period, 'last_number' => 0]);
            // Mengunci urutan agar dua beban tidak mengambil nomor berikutnya secara bersamaan.
            $row = DB::table('expense_number_sequences')->where('period', $period)->lockForUpdate()->first();
            $sequence = (int) $row->last_number + 1;
            if ($sequence > 999999) {
                throw ValidationException::withMessages(['expense' => 'Nomor beban bulan ini telah mencapai batas.']);
            }
            DB::table('expense_number_sequences')->where('period', $period)->update(['last_number' => $sequence]);
            // Menambahkan nol di kiri agar nomor urut memiliki enam digit.
            return 'BEB/'.$period.'/'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        }, 3);
    }
}
