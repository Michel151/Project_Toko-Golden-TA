<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemSource;
use App\Models\Stock;
use App\Models\StockBatch;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FefoStockIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_opname_adjustment_keeps_expirable_batches_in_sync(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        [$product, $location] = $this->makeExpirableProduct();

        Stock::create(['product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 100]);
        StockBatch::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'received_date' => today()->subDays(2)->toDateString(),
            'expired_date' => today()->addDays(7)->toDateString(),
            'quantity_initial' => 50,
            'quantity_remaining' => 50,
        ]);
        StockBatch::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'received_date' => today()->subDay()->toDateString(),
            'expired_date' => today()->addDays(30)->toDateString(),
            'quantity_initial' => 50,
            'quantity_remaining' => 50,
        ]);

        app(StockService::class)->adjustStock($product, $location, 90, null, today()->toDateString(), 'OPN-TEST');

        $this->assertDatabaseHas('stocks', [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 90,
        ]);
        $this->assertSame(90.0, (float) StockBatch::where('product_id', $product->id)->sum('quantity_remaining'));
    }

    public function test_sale_item_sources_store_fefo_batch_reference(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        [$product, $location, $productUnit] = $this->makeExpirableProduct();

        Stock::create(['product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 15]);
        $firstBatch = StockBatch::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'received_date' => today()->subDays(2)->toDateString(),
            'expired_date' => today()->addDays(7)->toDateString(),
            'quantity_initial' => 10,
            'quantity_remaining' => 10,
        ]);
        $secondBatch = StockBatch::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'received_date' => today()->subDay()->toDateString(),
            'expired_date' => today()->addDays(30)->toDateString(),
            'quantity_initial' => 5,
            'quantity_remaining' => 5,
        ]);

        $sale = Sale::create([
            'sale_number' => 'INV-TEST',
            'sale_date' => today()->toDateString(),
            'total_amount' => 15000,
            'payment_method' => 'tunai',
            'amount_paid' => 15000,
            'change_amount' => 0,
            'created_by' => $user->id,
        ]);
        $saleItem = SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_unit_id' => $productUnit->id,
            'sale_type' => 'ecer',
            'quantity' => 15,
            'unit_price' => 1000,
            'cost_price' => 700,
            'subtotal' => 15000,
            'cost_subtotal' => 10500,
        ]);

        $deductions = app(StockService::class)->deductStock(
            $product,
            $location,
            15,
            (string) $productUnit->id,
            $sale,
            $sale->sale_date,
            $sale->sale_number
        );
        foreach ($deductions as $deduction) {
            SaleItemSource::create([
                'sale_item_id' => $saleItem->id,
                'location_id' => $location->id,
                'stock_batch_id' => $deduction['stock_batch_id'],
                'quantity' => $deduction['quantity'],
            ]);
        }

        $this->assertDatabaseHas('sale_item_sources', [
            'sale_item_id' => $saleItem->id,
            'stock_batch_id' => $firstBatch->id,
            'quantity' => 10,
        ]);
        $this->assertDatabaseHas('sale_item_sources', [
            'sale_item_id' => $saleItem->id,
            'stock_batch_id' => $secondBatch->id,
            'quantity' => 5,
        ]);
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Owner',
            'email' => uniqid('owner').'@example.com',
            'password' => 'password',
            'role' => 'owner',
        ]);
    }

    private function makeExpirableProduct(): array
    {
        $category = Category::create(['name' => uniqid('Kategori ')]);
        $unit = Unit::create(['name' => uniqid('Pcs ')]);
        $location = Location::create(['name' => uniqid('Gudang ')]);
        $product = Product::create([
            'name' => uniqid('Susu '),
            'category_id' => $category->id,
            'buy_unit_id' => $unit->id,
            'buy_price' => 700,
            'sell_price_wholesale' => 1000,
            'can_retail' => false,
            'conversion_factor' => 1,
            'min_stock_total' => 5,
            'is_expirable' => true,
        ]);
        $productUnit = ProductUnit::create([
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'conversion_to_base' => 1,
            'buy_price' => 700,
            'sell_price' => 1000,
            'is_base_unit' => true,
            'is_default_purchase' => true,
            'is_default_sale' => true,
        ]);

        return [$product, $location, $productUnit];
    }
}
