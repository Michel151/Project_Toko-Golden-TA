<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductBuyPriceTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        $this->actingAs(User::create([
            'name' => 'Owner', 'email' => 'prices@example.com',
            'password' => 'password', 'role' => 'owner',
        ]));

        $units = [];
        foreach (['Dus', 'Bal', 'Slop', 'Bungkus'] as $index => $name) {
            $units[] = [
                'unit_id' => Unit::create(['name' => $name])->id,
                'contains_quantity' => [6, 10, 10, 16][$index],
                'contains_unit_index' => $index === 3 ? '' : $index + 1,
                'conversion_to_base' => 1,
                'buy_price' => $index === 0 ? '21.120.000' : 999,
                'sell_price' => [22000000, 3600000, 360000, 36000][$index],
            ];
        }

        return [
            'name' => 'Rokok', 'category_id' => Category::create(['name' => 'Rokok'])->id,
            'base_unit_index' => 3, 'default_purchase_unit_index' => 0,
            'default_sale_unit_index' => 2, 'product_units' => $units,
        ];
    }

    public function test_create_calculates_all_purchase_prices_and_preserves_sale_prices(): void
    {
        $payload = $this->payload();
        $this->post(route('master.products.store'), $payload)
            ->assertSessionHasNoErrors()->assertRedirect(route('master.products.index'));

        $product = Product::firstOrFail();
        $this->assertEquals(21120000, $product->buy_price);
        foreach ([21120000, 3520000, 352000, 35200] as $index => $price) {
            $this->assertDatabaseHas('product_units', [
                'product_id' => $product->id, 'unit_id' => $payload['product_units'][$index]['unit_id'],
                'buy_price' => $price, 'sell_price' => $payload['product_units'][$index]['sell_price'],
                'conversion_to_base' => [600, 100, 10, 1][$index],
            ]);
        }
        $this->get(route('master.products.edit', $product))->assertOk()->assertSee('buy-price-input');
    }

    public function test_update_uses_middle_purchase_unit_and_recalculates_changed_packaging(): void
    {
        $payload = $this->payload();
        $this->post(route('master.products.store'), $payload)->assertSessionHasNoErrors();
        $product = Product::firstOrFail();
        $payload['default_purchase_unit_index'] = 1;
        $payload['product_units'][0]['contains_quantity'] = 8;
        $payload['product_units'][1]['buy_price'] = '3.520.000';
        $this->put(route('master.products.update', $product), $payload)->assertSessionHasNoErrors();

        $this->assertEquals(3520000, $product->fresh()->buy_price);
        foreach ([28160000, 3520000, 352000, 35200] as $index => $price) {
            $this->assertDatabaseHas('product_units', [
                'product_id' => $product->id, 'unit_id' => $payload['product_units'][$index]['unit_id'],
                'buy_price' => $price, 'sell_price' => $payload['product_units'][$index]['sell_price'],
            ]);
        }
    }

    public function test_fractional_prices_are_rounded_from_reference_without_cascading_rounding(): void
    {
        $payload = $this->payload();
        $payload['product_units'][0]['buy_price'] = '1.000,25';
        $this->post(route('master.products.store'), $payload)->assertSessionHasNoErrors();
        foreach ([1000.25, 166.71, 16.67, 1.67] as $index => $price) {
            $this->assertDatabaseHas('product_units', [
                'unit_id' => $payload['product_units'][$index]['unit_id'], 'buy_price' => $price,
            ]);
        }
    }

    public function test_zero_reference_price_clears_all_derived_prices(): void
    {
        $payload = $this->payload();
        $payload['default_purchase_unit_index'] = 3;
        $payload['product_units'][3]['buy_price'] = 0;
        $this->post(route('master.products.store'), $payload)->assertSessionHasNoErrors();
        $this->assertSame(0.0, (float) Product::firstOrFail()->productUnits()->sum('buy_price'));
    }
}
