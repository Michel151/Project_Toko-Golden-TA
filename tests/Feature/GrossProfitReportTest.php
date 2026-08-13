<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\GrossProfitReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrossProfitReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_calculates_gross_profit_from_cost_snapshot(): void
    {
        $user = User::create([
            'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password', 'role' => 'owner',
        ]);
        $category = Category::create(['name' => 'Minuman']);
        $buyUnit = Unit::create(['name' => 'Dus']);
        $retailUnit = Unit::create(['name' => 'Botol']);
        $product = Product::create([
            'name' => 'Air Mineral',
            'category_id' => $category->id,
            'buy_unit_id' => $buyUnit->id,
            'buy_price' => 120000,
            'sell_price_wholesale' => 150000,
            'can_retail' => true,
            'retail_unit_id' => $retailUnit->id,
            'conversion_factor' => 10,
            'sell_price_retail' => 15000,
        ]);
        $sale = Sale::create([
            'sale_number' => 'INV-2026-000001',
            'sale_date' => '2026-07-12',
            'total_amount' => 375000,
            'payment_method' => 'tunai',
            'amount_paid' => 400000,
            'change_amount' => 25000,
            'created_by' => $user->id,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id, 'product_id' => $product->id, 'sale_type' => 'grosir',
            'quantity' => 2, 'unit_price' => 150000, 'cost_price' => 100000,
            'subtotal' => 300000, 'cost_subtotal' => 200000,
        ]);
        SaleItem::create([
            'sale_id' => $sale->id, 'product_id' => $product->id, 'sale_type' => 'ecer',
            'quantity' => 5, 'unit_price' => 15000, 'cost_price' => 10000,
            'subtotal' => 75000, 'cost_subtotal' => 50000,
        ]);

        $product->update(['buy_price' => 999999]);
        $result = app(GrossProfitReportService::class)->generate('2026-07-01', '2026-07-31');

        $this->assertSame(375000.0, $result['revenue']);
        $this->assertSame(250000.0, $result['cost']);
        $this->assertSame(125000.0, $result['gross_profit']);
        $this->assertEqualsWithDelta(33.3333, $result['margin'], 0.001);
        $this->assertSame(1, $result['transaction_count']);
        $this->assertSame(1, $result['product_count']);
        $this->assertSame(2.0, (float) $result['products']->first()->wholesale_qty);
        $this->assertSame(5.0, (float) $result['products']->first()->retail_qty);
    }

    public function test_owner_can_open_gross_profit_report(): void
    {
        $owner = User::create([
            'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password', 'role' => 'owner',
        ]);

        $this->actingAs($owner)->get(route('report.gross-profit'))->assertOk();
    }
}
