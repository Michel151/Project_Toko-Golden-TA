<?php

namespace Tests\Unit;

use App\Support\DisplayNumber;
use PHPUnit\Framework\TestCase;

class DisplayNumberTest extends TestCase
{
    public function test_quantities_keep_fractional_precision_without_unnecessary_zeros(): void
    {
        foreach (['10.0000'=>'10', '0.5000'=>'0,5', '2.1250'=>'2,125', '1234.5678'=>'1.234,5678', '0.0000'=>'0', '-2.5000'=>'-2,5'] as $value => $expected) {
            $this->assertSame($expected, DisplayNumber::quantity($value));
        }
        $this->assertSame('0', DisplayNumber::quantity(null));
    }
}
