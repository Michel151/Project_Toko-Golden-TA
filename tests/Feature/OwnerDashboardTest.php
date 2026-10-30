<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, ProductUnit, Sale, SaleItem, Stock, StockBatch, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OwnerDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_and_report_share_historical_quantities_and_tie_order(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-30 12:00:00', 'Asia/Jakarta'));
        $user = User::create(['name'=>'Owner', 'email'=>'ranking-parity@test.com', 'password'=>'password', 'role'=>'owner']);
        $this->actingAs($user);
        $category = Category::create(['name'=>'Perbandingan']);
        $pcs = Unit::create(['name'=>'Pcs']);
        $box = Unit::create(['name'=>'Dus']);
        $old = Unit::create(['name'=>'Bal']);
        $location = Location::create(['name'=>'Toko', 'is_active'=>true]);
        $sale = Sale::create(['sale_number'=>'INV-PARITY', 'sale_date'=>'2026-10-25', 'status'=>'paid',
            'total_amount'=>10000, 'payment_method'=>'tunai', 'created_by'=>$user->id]);
        $ids = [];
        foreach (['A snapshot', 'Z sources', 'Y conversion', 'B equal revenue'] as $i=>$name) {
            $product = Product::create(['name'=>$name, 'category_id'=>$category->id, 'buy_unit_id'=>$box->id,
                'buy_price'=>100, 'sell_price_wholesale'=>200, 'is_active'=>true]);
            $ids[] = $product->id;
            $base = ProductUnit::create(['product_id'=>$product->id, 'unit_id'=>$pcs->id, 'conversion_to_base'=>1, 'is_base_unit'=>true]);
            $main = ProductUnit::create(['product_id'=>$product->id, 'unit_id'=>$box->id, 'conversion_to_base'=>12, 'is_default_sale'=>true]);
            $changed = ProductUnit::create(['product_id'=>$product->id, 'unit_id'=>$old->id, 'conversion_to_base'=>100]);
            $item = $sale->items()->create(['product_id'=>$product->id,
                'product_unit_id'=>$i < 2 ? $changed->id : ($i === 2 ? $main->id : $base->id),
                'quantity'=>$i === 3 ? 24 : 2, 'cost_base_quantity'=>$i === 0 ? 24 : null,
                'sale_type'=>'grosir', 'unit_price'=>100, 'subtotal'=>[1000,3000,2000,2000][$i], 'cost_subtotal'=>100, 'cost_method'=>'moving_average']);
            if ($i < 2) {
                $item->sources()->create(['location_id'=>$location->id, 'quantity'=>1, 'base_quantity'=>$i === 0 ? 999 : 12]);
                $item->sources()->create(['location_id'=>$location->id, 'quantity'=>1, 'base_quantity'=>12]);
            }
        }
        $dashboard = $this->get(route('dashboard'))->assertOk()->viewData('topQuantityProducts');
        $report = $this->get(route('report.sale', ['from'=>'2026-10-01', 'to'=>'2026-10-30',
            'category_id'=>$category->id, 'products_sort'=>'quantity', 'tab'=>'products']))->assertOk()->viewData('topProducts');
        $expected = [$ids[1], $ids[2], $ids[3], $ids[0]];
        $this->assertSame($expected, $dashboard->pluck('id')->all());
        $this->assertSame($expected, $report->pluck('product_id')->all());
        foreach ($dashboard as $row) {
            $matching = $report->firstWhere('product_id', $row->id);
            $this->assertEquals(24, $row->base_quantity);
            $this->assertEquals($row->base_quantity, $matching->total_qty);
            $this->assertSame('2 Dus', $row->display_quantity);
            $this->assertSame($row->display_quantity, $matching->display_quantity);
        }
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
            ->assertViewHas('topQuantityProducts',fn($rows)=>(float)$rows->first()->base_quantity===3.0 && $rows->first()->package_unit==='Pcs')
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
            ->assertSee('Belum ada penjualan lunas bulan ini.')->assertDontSee('100,0%');
    }

    public function test_quantity_ranking_converts_mixed_units_to_main_sale_packages(): void
    {
        $user=User::create(['name'=>'Owner','email'=>'quantity-owner@example.com','password'=>'password','role'=>'owner']);
        $unit=Unit::create(['name'=>'Pcs']);
        $box=Unit::create(['name'=>'Dus']);
        $category=Category::create(['name'=>'Kategori']);
        $product=Product::create(['name'=>'Barang','category_id'=>$category->id,'buy_unit_id'=>$unit->id,'buy_price'=>500,'sell_price_wholesale'=>1000,'is_active'=>true]);
        $base=ProductUnit::create(['product_id'=>$product->id,'unit_id'=>$unit->id,'conversion_to_base'=>1,'buy_price'=>500,'sell_price'=>1000,'is_base_unit'=>true]);
        $bulk=ProductUnit::create(['product_id'=>$product->id,'unit_id'=>$box->id,'conversion_to_base'=>12,'buy_price'=>6000,'sell_price'=>12000,'is_default_sale'=>true]);
        $sale=Sale::create(['sale_number'=>'INV-MIXED','sale_date'=>now('Asia/Jakarta')->toDateString(),'status'=>'paid','total_amount'=>27000,'payment_method'=>'tunai','created_by'=>$user->id]);
        foreach ([[$base,3,1000,500],[$bulk,2,12000,6000]] as [$productUnit,$quantity,$price,$cost]) {
            SaleItem::create(['sale_id'=>$sale->id,'product_id'=>$product->id,'product_unit_id'=>$productUnit->id,'sale_type'=>'grosir','quantity'=>$quantity,'unit_price'=>$price,'subtotal'=>$quantity*$price,'cost_price'=>$cost,'cost_subtotal'=>$quantity*$cost]);
        }
        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertViewHas('topQuantityProducts',fn($rows)=>(float)$rows->first()->base_quantity===27.0 && $rows->first()->package_quantity===2.25 && $rows->first()->package_unit==='Dus')
            ->assertSee('2 Dus 3 Pcs')->assertSee('Jumlah Kemasan')->assertSee('Filter kategori produk terlaris')
            ->assertViewHas('grossProfit',13500);
    }
    public function test_package_ranking_keeps_all_candidates_and_categories_for_dashboard_filter(): void
    {
        $user = User::create(['name'=>'Owner', 'email'=>'package-owner@example.com', 'password'=>'password', 'role'=>'owner']);
        $baseUnit = Unit::create(['name'=>'Pcs']);
        $box = Unit::create(['name'=>'Dus']);
        $bal = Unit::create(['name'=>'Bal']);
        $category = Category::create(['name'=>'Kemasan']);
        $otherCategory = Category::create(['name'=>'Minuman']);
        $sale = Sale::create(['sale_number'=>'INV-PACKAGES', 'sale_date'=>today(), 'status'=>'paid', 'total_amount'=>10000, 'payment_method'=>'tunai', 'created_by'=>$user->id]);
        foreach ([['Teh Pucuk',126,12,$box], ['Aqua',11,1000,$box], ['Bal A',30,100,$bal], ['Bal B',29,100,$bal], ['Bal C',28,100,$bal], ['Bal D',27,100,$bal], ['Bal E',26,100,$bal]] as [$name,$packages,$factor,$unit]) {
            $product = Product::create(['name'=>$name, 'category_id'=>$unit->id === $box->id ? $otherCategory->id : $category->id, 'buy_unit_id'=>$unit->id, 'buy_price'=>1000, 'sell_price_wholesale'=>2000, 'is_active'=>true]);
            $base = ProductUnit::create(['product_id'=>$product->id, 'unit_id'=>$baseUnit->id, 'conversion_to_base'=>1, 'buy_price'=>1, 'sell_price'=>2, 'is_base_unit'=>true]);
            ProductUnit::create(['product_id'=>$product->id, 'unit_id'=>$unit->id, 'conversion_to_base'=>$factor, 'buy_price'=>1000, 'sell_price'=>2000, 'is_default_sale'=>true]);
            SaleItem::create(['sale_id'=>$sale->id, 'product_id'=>$product->id, 'product_unit_id'=>$base->id, 'sale_type'=>'ecer', 'quantity'=>$packages*$factor, 'unit_price'=>2, 'subtotal'=>$packages*$factor*2, 'cost_price'=>1, 'cost_subtotal'=>$packages*$factor]);
        }
        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertViewHas('topQuantityProducts', fn ($rows) => $rows->count() === 7
                && $rows->first()->name === 'Teh Pucuk'
                && $rows->first()->display_quantity === '126 Dus'
                && $rows->where('package_unit_id', $box->id)->pluck('name')->values()->all() === ['Teh Pucuk', 'Aqua'])
            ->assertViewHas('topProducts', fn ($rows) => $rows->count() === 7 && $rows->pluck('category_id')->unique()->count() === 2)
            ->assertViewHas('rankingCategories', fn ($rows) => $rows->pluck('name')->all() === ['Kemasan', 'Minuman'])
            ->assertSee('Filter kategori produk terlaris')->assertDontSee('Filter satuan kemasan')
            ->assertSee('<option value="revenue">Omzet</option>', false);
    }

    public function test_multi_unit_display_starts_at_main_sale_unit_and_preserves_fractional_remainder(): void
    {
        $user = User::create(['name'=>'Owner', 'email'=>'multi-display@example.com', 'password'=>'password', 'role'=>'owner']);
        $category = Category::create(['name'=>'Multi satuan']);
        $pcs = Unit::create(['name'=>'Pcs']);
        $pack = Unit::create(['name'=>'Pak']);
        $box = Unit::create(['name'=>'Dus']);
        $bal = Unit::create(['name'=>'Bal']);
        $product = Product::create(['name'=>'Multi kemasan', 'category_id'=>$category->id, 'buy_unit_id'=>$bal->id, 'buy_price'=>1000, 'sell_price_wholesale'=>2000, 'is_active'=>true]);
        foreach ([[$pcs,1],[$pack,6],[$box,24],[$bal,240]] as [$unit,$factor]) {
            $pu = ProductUnit::create(['product_id'=>$product->id, 'unit_id'=>$unit->id, 'conversion_to_base'=>$factor, 'buy_price'=>1000, 'sell_price'=>2000, 'is_base_unit'=>$factor===1, 'is_default_sale'=>$factor===24]);
            if ($factor === 1) $base = $pu;
        }
        $sale = Sale::create(['sale_number'=>'INV-MULTI-DISPLAY', 'sale_date'=>today(), 'status'=>'paid', 'total_amount'=>10000, 'payment_method'=>'tunai', 'created_by'=>$user->id]);
        $item = SaleItem::create(['sale_id'=>$sale->id, 'product_id'=>$product->id, 'product_unit_id'=>$base->id, 'sale_type'=>'ecer', 'quantity'=>249.5, 'unit_price'=>2, 'subtotal'=>499, 'cost_price'=>1, 'cost_subtotal'=>249.5]);
        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertViewHas('topQuantityProducts', fn ($rows) => $rows->first()->display_quantity === '10 Dus 1 Pak 3,5 Pcs'
                && $rows->first()->package_unit_id === $box->id
                && abs($rows->first()->package_quantity - 249.5 / 24) < 0.000001);
        $item->update(['quantity'=>48]);
        $this->get(route('dashboard'))->assertOk()
            ->assertViewHas('topQuantityProducts', fn ($rows) => $rows->first()->display_quantity === '2 Dus');
        $item->update(['quantity'=>3.5]);
        $this->get(route('dashboard'))->assertOk()
            ->assertViewHas('topQuantityProducts', fn ($rows) => $rows->first()->display_quantity === '3,5 Pcs'
                && $rows->first()->package_unit_id === $box->id);
    }

}
