<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, ProductUnit, Sale, SaleItem, Stock, StockBatch, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OwnerDashboardTest extends TestCase
{
    use RefreshDatabase;

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
            ->assertViewHas('topQuantityProducts',fn($rows)=>(float)$rows->first()->base_quantity===3.0 && $rows->first()->base_unit==='Pcs')
            ->assertSee('Kemarin belum ada penjualan')->assertSee('Laba Kotor Bulan Ini')
            ->assertSee('Sudah Kedaluwarsa')->assertSee('stok kedaluwarsa tidak dihitung')
            ->assertDontSee('Batch kedaluwarsa')->assertDontSee('batch kedaluwarsa tidak dihitung');
        SaleItem::whereHas('sale',fn($q)=>$q->whereDate('sale_date','2026-10-14')->paid())->update(['cost_subtotal'=>0]);
        $this->get(route('dashboard'))->assertOk()->assertViewHas('missingCostCount',1)->assertSee('Belum lengkap');
    }

    public function test_empty_dashboard_has_no_fabricated_growth_or_payment_percentages(): void
    {
        $user=User::create(['name'=>'Owner','email'=>'empty-owner@example.com','password'=>'password','role'=>'owner']);
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertViewHas('monthlyGrowth',null)
            ->assertViewHas('grossProfit',0)->assertViewHas('pendingSalesCount',0)
            ->assertSee('Belum ada penjualan lunas bulan ini.')->assertDontSee('100,0%');
    }

    public function test_quantity_ranking_converts_mixed_units_to_base_units(): void
    {
        $user=User::create(['name'=>'Owner','email'=>'quantity-owner@example.com','password'=>'password','role'=>'owner']);
        $unit=Unit::create(['name'=>'Pcs']);
        $box=Unit::create(['name'=>'Dus']);
        $category=Category::create(['name'=>'Kategori']);
        $product=Product::create(['name'=>'Barang','category_id'=>$category->id,'buy_unit_id'=>$unit->id,'buy_price'=>500,'sell_price_wholesale'=>1000,'is_active'=>true]);
        $base=ProductUnit::create(['product_id'=>$product->id,'unit_id'=>$unit->id,'conversion_to_base'=>1,'buy_price'=>500,'sell_price'=>1000,'is_base_unit'=>true]);
        $bulk=ProductUnit::create(['product_id'=>$product->id,'unit_id'=>$box->id,'conversion_to_base'=>12,'buy_price'=>6000,'sell_price'=>12000]);
        $sale=Sale::create(['sale_number'=>'INV-MIXED','sale_date'=>now('Asia/Jakarta')->toDateString(),'status'=>'paid','total_amount'=>27000,'payment_method'=>'tunai','created_by'=>$user->id]);
        foreach ([[$base,3,1000,500],[$bulk,2,12000,6000]] as [$productUnit,$quantity,$price,$cost]) {
            SaleItem::create(['sale_id'=>$sale->id,'product_id'=>$product->id,'product_unit_id'=>$productUnit->id,'sale_type'=>'grosir','quantity'=>$quantity,'unit_price'=>$price,'subtotal'=>$quantity*$price,'cost_price'=>$cost,'cost_subtotal'=>$quantity*$cost]);
        }
        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertViewHas('topQuantityProducts',fn($rows)=>(float)$rows->first()->base_quantity===27.0 && $rows->first()->base_unit==='Pcs')
            ->assertViewHas('grossProfit',13500);
    }
}
