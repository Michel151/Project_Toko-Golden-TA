<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductLocation;
use App\Models\Stock;
use App\Models\StockBatch;
use App\Models\Unit;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_expiring_batches_are_included_in_notifications(): void
    {
        Carbon::setTestNow('2026-09-27');

        $location = Location::create(['name' => 'Gudang']);
        $unit = Unit::create(['name' => 'Pcs']);
        $category = Category::create(['name' => 'Makanan']);
        $product = Product::create([
            'name' => 'Susu UHT',
            'category_id' => $category->id,
            'buy_unit_id' => $unit->id,
            'buy_price' => 7000,
            'sell_price_wholesale' => 9000,
            'is_expirable' => true,
            'min_stock_total' => 0,
        ]);

        StockBatch::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'received_date' => '2026-08-01',
            'expired_date' => '2026-09-20',
            'quantity_initial' => 5,
            'quantity_remaining' => 2,
        ]);
        StockBatch::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'received_date' => '2026-09-01',
            'expired_date' => '2026-10-20',
            'quantity_initial' => 10,
            'quantity_remaining' => 4,
        ]);
        StockBatch::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'received_date' => '2026-09-01',
            'expired_date' => '2026-11-15',
            'quantity_initial' => 10,
            'quantity_remaining' => 10,
        ]);

        $notifications = app(NotificationService::class)->checkAllProducts();

        $this->assertCount(1, $notifications);
        $this->assertSame('expiry', $notifications[0]['type']);
        $this->assertSame('Kedaluwarsa', $notifications[0]['badge_label']);
        $this->assertSame('danger', $notifications[0]['badge_class']);
        $this->assertSame([
            ['label' => 'Kedaluwarsa', 'class' => 'danger'],
            ['label' => 'Hampir Kedaluwarsa', 'class' => 'warning text-dark'],
        ], $notifications[0]['badges']);
        $this->assertStringContainsString('sudah kedaluwarsa sejak 20/09/2026', $notifications[0]['message']);
        $this->assertStringContainsString('akan kedaluwarsa pada 20/10/2026', $notifications[0]['message']);
        $this->assertStringContainsString('Stok Susu UHT di Gudang', $notifications[0]['message']);
        $this->assertDoesNotMatchRegularExpression('/\bbatch\b|Hampir Expired/i', $notifications[0]['message']);

        $owner = \App\Models\User::create(['name'=>'Owner','email'=>'notification-wording@example.com','password'=>'password','role'=>'owner']);
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertSee('Hampir Kedaluwarsa')->assertSee('Stok Susu UHT di Gudang')
            ->assertDontSee('Hampir Expired')->assertDontSee('Batch Susu UHT');

        Carbon::setTestNow();
    }

    public function test_stock_notifications_are_grouped_by_product(): void
    {
        $unit = Unit::create(['name' => 'Pcs']);
        $category = Category::create(['name' => 'Kebutuhan Rumah']);
        $product = Product::create([
            'name' => 'Dettol 50 Lembar',
            'category_id' => $category->id,
            'buy_unit_id' => $unit->id,
            'buy_price' => 10000,
            'sell_price_wholesale' => 12000,
            'min_stock_total' => 20,
        ]);
        $locations = collect(['Gudang 2', 'Gudang 3', 'Toko'])->map(
            fn (string $name) => Location::create(['name' => $name])
        );

        foreach ($locations as $index => $location) {
            ProductLocation::create([
                'product_id' => $product->id,
                'location_id' => $location->id,
                'min_stock' => 10,
            ]);
            Stock::create([
                'product_id' => $product->id,
                'location_id' => $location->id,
                'quantity' => $index + 1,
            ]);
        }

        $notifications = app(NotificationService::class)->checkAllProducts();

        $this->assertCount(1, $notifications);
        $this->assertSame('Dettol 50 Lembar', $notifications[0]['product_name']);
        $this->assertSame('3 lokasi', $notifications[0]['location_name']);
        $this->assertSame([['label' => 'Stok Rendah', 'class' => 'warning text-dark']], $notifications[0]['badges']);
        $this->assertEqualsCanonicalizing(['Gudang 2', 'Gudang 3', 'Toko'], $notifications[0]['location_names']);
        $this->assertStringContainsString('Total stok layak jual Dettol 50 Lembar rendah', $notifications[0]['message']);
        $this->assertStringContainsString('order ke supplier', $notifications[0]['advice']);
    }
}
