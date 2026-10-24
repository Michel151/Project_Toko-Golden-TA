<?php

namespace Tests\Unit;

use App\Models\{Product, ProductUnit, Unit};
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class ProductStockDisplayTest extends TestCase
{
    private function product(array $units): Product
    {
        $product = new Product;
        $product->setRelation('productUnits', new Collection(array_map(function ($row) {
            [$name, $conversion] = $row;
            $unit = new ProductUnit(['conversion_to_base' => $conversion, 'is_base_unit' => $conversion === 1]);
            $unit->setRelation('unit', new Unit(['name' => $name]));
            return $unit;
        }, $units)));
        return $product;
    }

    public function test_fractional_base_stock_is_preserved_with_four_decimal_precision(): void
    {
        $product = $this->product([['Kg', 1]]);
        foreach ([
            ['0.5', '0,5 Kg'], ['1.5', '1,5 Kg'], ['0.0001', '0,0001 Kg'],
            ['1234.5678', '1.234,5678 Kg'], ['0', '0 Kg'], ['2', '2 Kg'], ['-1.5', '-1,5 Kg'],
        ] as [$quantity, $expected]) {
            $this->assertSame($expected, $product->displayStock((float) $quantity));
        }
    }

    public function test_package_breakdown_keeps_the_fractional_remainder(): void
    {
        // Deliberately unsorted to also check the existing unit ordering.
        $product = $this->product([['Kg', 1], ['Karung', 25], ['Pak', 5]]);
        $this->assertSame('1 Karung 1 Pak 1,25 Kg', $product->displayStock(31.25));
        $this->assertSame('1 Karung 0,0001 Kg', $product->displayStock(25.0001));
        $this->assertSame('0,5 Kg', $product->displayStock(0.5));
        $this->assertSame('1 Karung', $product->displayStock(25));
        $this->assertSame('0 Kg', $product->displayStock(0));
        $this->assertSame('-(1 Karung 0,5 Kg)', $product->displayStock(-25.5));
    }

    public function test_decimal_package_conversion_does_not_lose_stock_to_float_remainders(): void
    {
        $product = $this->product([['Pak', 2.1], ['Kg', 1]]);
        $this->assertSame('3 Pak', $product->displayStock(6.3));
        $this->assertSame('3 Pak 0,0001 Kg', $product->displayStock(6.3001));
        $this->assertSame('1 Pak 0,1 Kg', $product->displayStock(2.2));
    }
}
