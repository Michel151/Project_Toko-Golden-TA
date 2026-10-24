<?php

namespace App\Support;

class DisplayNumber
{
    public static function quantity(float|string|null $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, ',', '.'), '0'), ',');
    }
}
