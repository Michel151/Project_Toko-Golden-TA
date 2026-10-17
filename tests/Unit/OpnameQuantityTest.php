<?php

namespace Tests\Unit;

use App\Support\OpnameQuantity;
use PHPUnit\Framework\TestCase;

class OpnameQuantityTest extends TestCase
{
    public function test_stacked_units_preserve_fractional_base_quantities_and_signs(): void
    {
        $units = [['name'=>'Dus','conversion'=>100], ['name'=>'Slop','conversion'=>10], ['name'=>'Bks','conversion'=>1]];
        $this->assertSame('2 Dus + 3 Slop + 4,25 Bks', OpnameQuantity::format(234.25, $units));
        $this->assertSame('-(2 Dus + 3 Slop + 4,25 Bks)', OpnameQuantity::format(-234.25, $units, true));
        $this->assertSame('+1 Slop', OpnameQuantity::format(10, $units, true));
        $this->assertSame('0 Bks', OpnameQuantity::format(0, $units, true));
        $this->assertSame('0,0001 Bks', OpnameQuantity::format(0.0001, $units));
    }

    public function test_bottles_and_decimal_weight_are_not_rounded_away(): void
    {
        $units = [['name'=>'Dus','conversion'=>24], ['name'=>'Botol','conversion'=>1]];
        $this->assertSame('1 Dus + 5 Botol', OpnameQuantity::format(29, $units));
        $this->assertSame('1 Dus', OpnameQuantity::format(24, $units));
        $this->assertSame('57,5 Kg', OpnameQuantity::format(57.5, [['name'=>'Kg','conversion'=>1]]));
    }
}
