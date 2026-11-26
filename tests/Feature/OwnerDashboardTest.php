<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, ProductUnit, Sale, SaleItem, Stock, StockBatch, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OwnerDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_sales_aggregate_paid_subtotals_in_the_last_thirty_days(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-30 12:00:00', 'Asia/Jakarta'));
        $owner = User::create(['name'=>'Owner', 'email'=>'category-dashboard@test.com', 'password'=>'password', 'role'=>'owner']);
        $this->actingAs($owner);
        $unit = Unit::create(['name'=>'Bungkus']);
        $firstCategory = Category::create(['name'=>'Rokok']);
        $secondCategory = Category::create(['name'=>'Minuman']);
        $firstProduct = Product::create(['name'=>'Rokok A', 'category_id'=>$firstCategory->id, 'buy_unit_id'=>$unit->id, 'is_active'=>true]);
        $secondProduct = Product::create(['name'=>'Rokok B', 'category_id'=>$firstCategory->id, 'buy_unit_id'=>$unit->id, 'is_active'=>false]);
        $drink = Product::create(['name'=>'Minuman A', 'category_id'=>$secondCategory->id, 'buy_unit_id'=>$unit->id, 'is_active'=>true]);
        $add = function ($date, $status, $product, $amount) use ($owner) {
            $sale = Sale::create(['sale_number'=>'INV-'.Str::uuid(), 'sale_date'=>$date, 'status'=>$status,
                'total_amount'=>$amount, 'payment_method'=>'tunai', 'created_by'=>$owner->id]);
            return SaleItem::create(['sale_id'=>$sale->id, 'product_id'=>$product->id, 'quantity'=>1,
                'sale_type'=>'ecer', 'unit_price'=>$amount, 'subtotal'=>$amount, 'cost_subtotal'=>1]);
        };
        $add('2026-10-01', 'paid', $firstProduct, 400);
        $add('2026-10-30', 'paid', $secondProduct, 200);
        $add('2026-10-15', 'paid', $drink, 400);
        foreach ([['2026-09-30','paid'], ['2026-10-31','paid'], ['2026-10-15','pending'], ['2026-10-15','cancelled']] as [$date,$status]) {
            $add($date, $status, $firstProduct, 9000);
        }
        $before = SaleItem::orderBy('id')->get()->toJson();
        $response = $this->get(route('dashboard'))->assertOk()
            ->assertSee('Penjualan per Kategori')->assertSee('60,0%')->assertSee('40,0%')
            ->assertDontSee('Produk Terlaris')->assertDontSee('rankingMode')->assertViewHas('categorySalesTotal',1000.0);
        $rows = $response->viewData('categorySales');
        $this->assertSame(['Rokok','Minuman'], $rows->pluck('name')->all());
        $this->assertEquals([600,400], $rows->pluck('revenue')->all());
        $this->assertEquals([60,40], $rows->pluck('contribution')->all());
        $this->assertSame($before, SaleItem::orderBy('id')->get()->toJson());
    }

    public function test_owner_metrics_use_paid_sales_historical_cost_and_matching_dates(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-14 10:00:00', 'Asia/Jakarta'));
        $user=User::create(['name'=>'Owner','email'=>'owner-test@example.com','password'=>'password','role'=>'owner']);
        $unit=Unit::create(['name'=>'Pcs']);
        $category=Category::create(['name'=>'Kategori']);
        $location=Location::create(['name'=>'Gudang']);
        $product=Product::create(['name'=>'Barang Uji','category_id'=>$category->id,'buy_unit_id'=>$unit->id,'buy_price'=>9999,'sell_price_wholesale'=>10000,'is_active'=>true,'is_expirable'=>true]);
        $productUnit=ProductUnit::create(['product_id'=>$product->id,'unit_id'=>$unit->id,'conversion_to_base'=>1,'buy_price'=>500,'sell_price'=>1000,'is_base_unit'=>true,'is_default_purchase'=>true]);
        Stock::create(['product_id'=>$product->id,'location_id'=>$location->id,'quantity'=>10]);
        StockBatch::create(['product_id'=>$product->id,'location_id'=>$location->id,'received_date'=>'2026-09-01','expired_date'=>'2026-10-13','quantity_initial'=>10,'quantity_remaining'=>10]);
        $make=function ($date,$status,$amount,$cost,$method='tunai',$quantity=1) use ($user,$product,$productUnit) {
            $sale=Sale::create(['sale_number'=>'INV-'.Str::uuid(),'sale_date'=>$date,'status'=>$status,'total_amount'=>$amount,'payment_method'=>$method,'amount_paid'=>$amount+5000,'created_by'=>$user->id]);
            SaleItem::create(['sale_id'=>$sale->id,'product_id'=>$product->id,'product_unit_id'=>$productUnit->id,'sale_type'=>'ecer','quantity'=>$quantity,'unit_price'=>$amount/$quantity,'subtotal'=>$amount,'cost_price'=>$cost/$quantity,'cost_subtotal'=>$cost]);
            return $sale;
        };
        $make('2026-10-14','paid',10000,6000);
        $make('2026-10-01','paid',20000,10000,'transfer');
        $make('2026-09-14','paid',15000,10000);
        $make('2026-09-20','paid',999999,10000);
        $make('2026-10-15','paid',888888,10000);
        $make('2026-10-14','pending',30000,10000);
        $make('2026-10-14','cancelled',777777,10000);
        $response=$this->actingAs($user)->get(route('dashboard'));
        $response->assertOk()->assertViewIs('dashboard.index')->assertViewHas('salesToday',10000)
            ->assertViewHas('salesMonth',30000)->assertViewHas('grossProfit',14000)->assertViewHas('missingCostCount',0)
            ->assertViewHas('dailyGrowth',null)->assertViewHas('monthlyGrowth',100)
            ->assertViewHas('pendingSalesCount',1)->assertViewHas('pendingSalesAmount',30000)
            ->assertViewHas('paymentMethods',fn($values)=>(float)$values['tunai']===10000.0 && (float)$values['transfer']===20000.0)
            ->assertViewHas('salesChart',fn($rows)=>$rows->count()===30 && (float)$rows->last()['total']===10000.0)
            ->assertViewHas('outOfStockCount',1)->assertViewHas('expiredBatchCount',1)
            ->assertViewHas('inventoryValue',5000)->assertViewHas('locationValues',fn($rows)=>(float)$rows->sum('value')===5000.0)
            ->assertViewHas('categorySalesTotal',1029999.0)
            ->assertViewHas('categorySales',fn($rows)=>$rows->count()===1 && (float)$rows->first()->revenue===1029999.0 && $rows->first()->contribution===100.0)
            ->assertSee('Kemarin belum ada penjualan')->assertSee('Laba Kotor Bulan Ini')
            ->assertSee('Kedaluwarsa')->assertSee('1 produk perlu perhatian')->assertSee('stok kedaluwarsa tidak dihitung')
            ->assertDontSee('Batch kedaluwarsa')->assertDontSee('batch kedaluwarsa tidak dihitung');
        SaleItem::whereHas('sale',fn($q)=>$q->whereDate('sale_date','2026-10-14')->paid())->update(['cost_subtotal'=>0]);
        $this->get(route('dashboard'))->assertOk()->assertViewHas('missingCostCount',1)->assertSee('Belum lengkap');
    }

    public function test_empty_dashboard_has_no_fabricated_growth_or_payment_percentages(): void
    {
        $user=User::create(['name'=>'Owner','email'=>'empty-owner@example.com','password'=>'password','role'=>'owner']);
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertViewHas('monthlyGrowth',null)
            ->assertViewHas('grossProfit',0)->assertViewHas('pendingSalesCount',0)
            ->assertViewHas('categorySalesTotal',0.0)->assertViewHas('categorySales',fn($rows)=>$rows->isEmpty())
            ->assertSee('Belum ada penjualan lunas dalam 30 hari terakhir.')
            ->assertSee('Belum ada penjualan lunas bulan ini.')->assertDontSee('100,0%');
    }

}
