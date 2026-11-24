<?php

namespace App\Support;

use Carbon\CarbonInterface;

final class ExpiryStatus
{
    public const WARNING_DAYS = 90;
    public const URGENT_DAYS = 30;

    // Menentukan kode status dari selisih hari terhadap tanggal pembanding atau hari ini.
    public static function classify(?CarbonInterface $date, ?CarbonInterface $reference = null): string
    {
        if (! $date) return 'undated';
        $days = (int) ($reference ?? today())->copy()->startOfDay()->diffInDays($date->copy()->startOfDay(), false);

        return match (true) {
            $days < 0 => 'expired',
            $days === 0 => 'today',
            $days <= self::URGENT_DAYS => 'urgent',
            $days <= self::WARNING_DAYS => 'soon',
            default => 'safe',
        };
    }

    // Mengubah kode status kedaluwarsa menjadi tulisan untuk pengguna.
    public static function label(string $status): string
    {
        return match ($status) {
            'expired' => 'Kedaluwarsa',
            'today' => 'Kedaluwarsa Hari Ini',
            'urgent' => 'Segera Kedaluwarsa',
            'soon' => 'Mendekati Kedaluwarsa',
            'safe' => 'Aman',
            default => 'Tidak Ada Kedaluwarsa',
        };
    }

    // Memilih class warna badge berdasarkan tulisan status kedaluwarsa.
    public static function badge(string $label): string
    {
        return match ($label) {
            'Kedaluwarsa', 'Kedaluwarsa Hari Ini' => 'danger',
            'Segera Kedaluwarsa' => 'expiry-urgent',
            'Mendekati Kedaluwarsa' => 'warning text-dark',
            'Aman' => 'success',
            default => 'secondary',
        };
    }
}
