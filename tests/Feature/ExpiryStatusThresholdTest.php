<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, Stock, StockBatch, Unit, User};
use App\Services\NotificationService;
use App\Support\ExpiryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpiryStatusThresholdTest extends TestCase
{
    use RefreshDatabase;

    public function test_boundaries_filters_notifications_and_sellable_stock_agree(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-23 15:00:00'));
        $this->actingAs(User::create(['name'=>'Owner','email'=>'expiry-boundaries@example.com','password'=>'password','role'=>'owner']));
        $category = Category::create(['name'=>'Makanan']);
        $unit = Unit::create(['name'=>'Dus']);
        $location = Location::create(['name'=>'Toko']);
        $expected = [-1=>'expired', 0=>'today', 1=>'urgent', 30=>'urgent', 31=>'soon', 60=>'soon', 90=>'soon', 91=>'safe'];
        $ids = [];
        foreach ($expected as $days => $status) {
            $product = Product::create(['name'=>'Produk '.$days,'category_id'=>$category->id,'buy_unit_id'=>$unit->id,
                'buy_price'=>1000,'sell_price_wholesale'=>1500,'is_active'=>true,'is_expirable'=>true,'min_stock_total'=>0]);
            $batch = StockBatch::create(['product_id'=>$product->id,'location_id'=>$location->id,'received_date'=>today()->subDay(),
                'expired_date'=>today()->addDays($days),'quantity_initial'=>10,'quantity_remaining'=>10]);
            Stock::create(['product_id'=>$product->id,'location_id'=>$location->id,'quantity'=>10]);
            $this->assertSame($status, ExpiryStatus::classify($batch->expired_date));
            $this->assertSame(ExpiryStatus::label($status), $batch->expiry_status);
            $this->assertSame($days < 0 ? 0.0 : 10.0, $product->fresh()->stockAvailability()['sellable']);
            $ids[$status][] = $product->id;
        }
        $this->assertSame('undated', ExpiryStatus::classify(null));
        $notifications = collect(app(NotificationService::class)->checkAllProducts());
        $this->assertCount(7, $notifications);
        $this->assertSame(['expired','expiry_today','expiry_urgent','expiry_urgent','expiring','expiring','expiring'], $notifications->pluck('kind')->all());
        $this->assertSame(7, app(NotificationService::class)->countNotifications());
        foreach (['expired'=>'expired','expiry_today'=>'today','expiry_urgent'=>'urgent','expiring'=>'soon','safe'=>'safe'] as $filter => $status) {
            $this->get(route('inventory.stock.index', ['expiry_status'=>$filter, 'location_id'=>$location->id]))->assertOk()
                ->assertViewHas('products', fn ($products) => $products->pluck('id')->sort()->values()->all() === collect($ids[$status])->sort()->values()->all());
            $reportFilter = $filter === 'expiring' ? 'expiring_soon' : $filter;
            $this->get(route('report.stock', ['expiry_status'=>$reportFilter, 'location_id'=>$location->id]))->assertOk()
                ->assertViewHas('products', fn ($products) => $products->pluck('id')->sort()->values()->all() === collect($ids[$status])->sort()->values()->all());
        }
        foreach (['expiry_urgent'=>'urgent','expiring'=>'soon'] as $filter => $status) {
            $this->get(route('notifications.index', ['condition'=>$filter]))->assertOk()
                ->assertViewHas('summary', fn ($summary) => $summary['expiry'] === count($ids[$status]));
        }

        // A safe batch must not hide a risky batch of the same product/location.
        $mixed = Product::find($ids['safe'][0]);
        $warehouse = Location::create(['name'=>'Gudang']);
        Stock::create(['product_id'=>$mixed->id,'location_id'=>$warehouse->id,'quantity'=>10]);
        $risky = StockBatch::create(['product_id'=>$mixed->id,'location_id'=>$warehouse->id,'received_date'=>today(),
            'expired_date'=>today()->addDays(30),'quantity_initial'=>10,'quantity_remaining'=>10]);
        foreach (['inventory.stock.index','report.stock'] as $route) {
            $this->get(route($route, ['expiry_status'=>'safe']))->assertOk()
                ->assertViewHas('products', fn ($products) => ! $products->contains('id', $mixed->id));
            $this->get(route($route, ['expiry_status'=>'safe','location_id'=>$location->id]))->assertOk()
                ->assertViewHas('products', fn ($products) => $products->contains('id', $mixed->id));
        }
        $risky->update(['quantity_remaining'=>0]);
        foreach (['inventory.stock.index','report.stock'] as $route) {
            $this->get(route($route, ['expiry_status'=>'safe']))->assertOk()
                ->assertViewHas('products', fn ($products) => $products->contains('id', $mixed->id));
        }
    }
}
