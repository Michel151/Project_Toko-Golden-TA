<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockBatch;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryStockAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_open_stock_monitoring(): void
    {
        $cashier = User::create([
            'name' => 'Kasir',
            'email' => 'kasir-stock@example.com',
            'password' => 'password',
            'role' => 'kasir',
        ]);

        $this->actingAs($cashier)
            ->get(route('inventory.stock.index'))
            ->assertOk()
            ->assertSee('Monitoring Stok')
            ->assertSee('Monitoring Persediaan')
            ->assertDontSee('Transfer Stok')
            ->assertDontSee('Stock Opname');
    }

    public function test_cashier_can_open_stock_card_but_cannot_open_stock_transfer(): void
    {
        $cashier = User::create([
            'name' => 'Kasir',
            'email' => 'kasir-stock-card@example.com',
            'password' => 'password',
            'role' => 'kasir',
        ]);
        $category = Category::create(['name' => 'Kategori Tes']);
        $unit = Unit::create(['name' => 'Pcs']);
        $product = Product::create([
            'name' => 'Produk Tes',
            'category_id' => $category->id,
            'buy_unit_id' => $unit->id,
            'buy_price' => 10000,
            'sell_price_wholesale' => 12000,
        ]);

        $this->actingAs($cashier)
            ->get(route('inventory.stock-card.show', $product))
            ->assertOk();

        $this->actingAs($cashier)
            ->get(route('inventory.transfers.create'))
            ->assertForbidden();
    }

    public function test_stock_monitoring_can_filter_by_expiry_status(): void
    {
        Carbon::setTestNow('2026-09-26');

        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner-stock-expiry@example.com',
            'password' => 'password',
            'role' => 'owner',
        ]);
        $category = Category::create(['name' => 'Minuman']);
        $unit = Unit::create(['name' => 'Pcs']);
        $location = Location::create(['name' => 'Gudang']);

        $expiredProduct = $this->makeProduct('Roti Kedaluwarsa', $category, $unit);
        $expiringProduct = $this->makeProduct('Yogurt Segera Dicek', $category, $unit);
        $safeProduct = $this->makeProduct('Sirup Jangka Panjang', $category, $unit);

        $this->makeStockBatch($expiredProduct, $location, '2026-09-25');
        $this->makeStockBatch($expiringProduct, $location, '2026-10-20');
        $this->makeStockBatch($safeProduct, $location, '2026-11-30');

        $this->actingAs($owner)
            ->get(route('inventory.stock.index', ['expiry_status' => 'expired']))
            ->assertOk()
            ->assertSee('Roti Kedaluwarsa')
            ->assertDontSee('Yogurt Segera Dicek')
            ->assertDontSee('Sirup Jangka Panjang');

        $this->actingAs($owner)
            ->get(route('inventory.stock.index', ['expiry_status' => 'expiring']))
            ->assertOk()
            ->assertDontSee('Roti Kedaluwarsa')
            ->assertSee('Yogurt Segera Dicek')
            ->assertDontSee('Sirup Jangka Panjang');

        $this->actingAs($owner)
            ->get(route('inventory.stock.index', ['expiry_status' => 'safe']))
            ->assertOk()
            ->assertDontSee('Roti Kedaluwarsa')
            ->assertDontSee('Yogurt Segera Dicek')
            ->assertSee('Sirup Jangka Panjang');

        Carbon::setTestNow();
    }

    private function makeProduct(string $name, Category $category, Unit $unit): Product
    {
        return Product::create([
            'name' => $name,
            'category_id' => $category->id,
            'buy_unit_id' => $unit->id,
            'buy_price' => 10000,
            'sell_price_wholesale' => 12000,
            'is_expirable' => true,
            'min_stock_total' => 0,
        ]);
    }

    private function makeStockBatch(Product $product, Location $location, string $expiredDate): void
    {
        Stock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 10,
        ]);

        StockBatch::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'received_date' => '2026-09-01',
            'expired_date' => $expiredDate,
            'quantity_initial' => 10,
            'quantity_remaining' => 10,
        ]);
    }
}
