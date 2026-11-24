<?php

namespace App\Support;

class DisplayNumber
{
    // Memformat jumlah dengan koma desimal dan menghapus nol desimal yang tidak diperlukan.
    public static function quantity(float|string|null $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, ',', '.'), '0'), ',');
    }
}
