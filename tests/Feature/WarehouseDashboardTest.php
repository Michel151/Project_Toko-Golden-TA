<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Stock;
use App\Models\StockBatch;
use App\Models\StockOpname;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WarehouseDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        $user=User::create(['name'=>'Gudang','email'=>'warehouse@example.com','password'=>'password','role'=>'staf_gudang']);
        $this->actingAs($user);
        return $user;
    }

    public function test_sellable_stock_expiry_priorities_and_location_counts_are_consistent(): void
    {
        $this->staff();
        $day=now('Asia/Jakarta')->startOfDay();
        $category=Category::create(['name'=>'Kategori']);
        $unit=Unit::create(['name'=>'Pcs']);
        $location=Location::create(['name'=>'Gudang Uji']);
        Location::create(['name'=>'Lokasi Kosong']);
        $make=function ($name,$quantity,$expirable,$minimum=0,$active=true) use ($category,$unit,$location) {
            $product=Product::create(['name'=>$name,'category_id'=>$category->id,'buy_unit_id'=>$unit->id,'buy_price'=>500,'sell_price_wholesale'=>1000,'can_retail'=>false,'conversion_factor'=>1,'min_stock_total'=>$minimum,'is_expirable'=>$expirable,'is_active'=>$active]);
            ProductUnit::create(['product_id'=>$product->id,'unit_id'=>$unit->id,'conversion_to_base'=>1,'buy_price'=>500,'sell_price'=>1000,'is_base_unit'=>true,'is_default_sale'=>true,'is_default_purchase'=>true]);
            Stock::create(['product_id'=>$product->id,'location_id'=>$location->id,'quantity'=>$quantity]);
            return $product;
        };
        $batch=fn ($product,$quantity,$expiry) => StockBatch::create(['product_id'=>$product->id,'location_id'=>$location->id,'received_date'=>$day->copy()->subDays(40)->toDateString(),'expired_date'=>$expiry,'quantity_initial'=>$quantity,'quantity_remaining'=>$quantity]);
        $expired=$make('Hanya Kedaluwarsa',10,true);
        $batch($expired,10,$day->copy()->subDay()->toDateString());
        $mixed=$make('Campuran',20,true,15);
        $batch($mixed,10,$day->copy()->subDays(2)->toDateString());
        $batch($mixed,2,$day->toDateString());
        $batch($mixed,3,$day->copy()->addDays(30)->toDateString());
        $batch($mixed,5,$day->copy()->addDays(31)->toDateString());
        $undated=$make('Tanpa Tanggal',3,true);
        $batch($undated,3,null);
        $make('Barang Aman',10,false,2);
        $make('Stok Nol',0,false);
        $inactive=$make('Produk Nonaktif',20,true,0,false);
        $batch($inactive,20,$day->copy()->subDay()->toDateString());
        $response=$this->get(route('dashboard'));
        $response->assertOk()->assertViewIs('dashboard.warehouse')
            ->assertViewHas('warehouseProductCount',5)->assertViewHas('warehouseOutOfStockCount',2)
            ->assertViewHas('warehouseLowStockCount',1)->assertViewHas('warehouseSafeStockCount',2)
            ->assertViewHas('expiredBatchCount',2)->assertViewHas('expiringBatchCount',2)
            ->assertViewHas('warehousePriorityCount',3)
            ->assertViewHas('warehousePriorities',fn($rows)=>$rows->first()['badge_label']==='Kedaluwarsa' && $rows->first()['product_name']==='Campuran' && $rows->get(2)['badge_label']==='Stok Habis')
            ->assertViewHas('stockedLocations',fn($rows)=>$rows->first()['sellable']===3 && $rows->first()['physical']===4)
            ->assertDontSee('Produk Nonaktif')->assertDontSee('chart.umd')->assertSee('Stok Layak Jual Habis')
            ->assertSee('Sudah Kedaluwarsa')->assertSee('Dari 1 produk, kedaluwarsa hari ini hingga 30 hari ke depan')->assertSee('2 batch')->assertSee('Berdasarkan total stok seluruh lokasi')->assertViewHas('expiredProductCount',2)->assertViewHas('expiringProductCount',1)
            ->assertDontSee('Batch Kedaluwarsa')->assertDontSee('Batch dalam 30 hari');
        $this->assertDatabaseHas('stocks',['product_id'=>$expired->id,'quantity'=>10]);
    }

    public function test_work_queues_only_include_unfinished_receipts_and_opnames_and_respect_permissions(): void
    {
        $user=$this->staff();
        $location=Location::create(['name'=>'Gudang']);
        $category=Category::create(['name'=>'Kategori']);
        $unit=Unit::create(['name'=>'Pcs']);
        $product=Product::create(['name'=>'Barang','category_id'=>$category->id,'buy_unit_id'=>$unit->id,'buy_price'=>500,'sell_price_wholesale'=>1000,'is_active'=>true]);
        $supplier=Supplier::create(['name'=>'Supplier Uji']);
        foreach ([['PO-PENDING','pending',0,1],['PO-PARTIAL','partial',8,3],['PO-DONE','completed',10,4],['PO-STALE','partial',10,2]] as [$number,$status,$received,$age]) {
            $order=PurchaseOrder::create(['po_number'=>$number,'supplier_id'=>$supplier->id,'order_date'=>today()->subDays($age),'status'=>$status,'created_by'=>$user->id]);
            PurchaseOrderItem::create(['purchase_order_id'=>$order->id,'product_id'=>$product->id,'quantity_ordered'=>10,'quantity_received'=>$received,'buy_price'=>12345678]);
        }
        foreach ([['OP-OLD','draft',3],['OP-NEW','draft',1],['OP-DONE','completed',4]] as [$number,$status,$age]) {
            StockOpname::create(['opname_number'=>$number,'location_id'=>$location->id,'opname_date'=>today()->subDays($age),'status'=>$status,'created_by'=>$user->id]);
        }
        $this->get(route('dashboard'))->assertOk()->assertViewHas('pendingReceiptCount',2)->assertViewHas('draftOpnames',2)
            ->assertViewHas('pendingReceipts',fn($orders)=>$orders->first()->po_number==='PO-PARTIAL' && $orders->first()->remaining_items_count===1)
            ->assertViewHas('opnameQueue',fn($opnames)=>$opnames->first()->opname_number==='OP-OLD')
            ->assertDontSee('PO-DONE')->assertDontSee('PO-STALE')->assertDontSee('OP-DONE')->assertDontSee('12.345.678');
        DB::table('role_permissions')->where('role','staf_gudang')->where('permission','like','purchase.receipts.%')->delete();
        $this->get(route('dashboard'))->assertOk()->assertViewHas('pendingReceiptCount',0)->assertDontSee('PO-PENDING')->assertDontSee('Penerimaan Belum Lengkap');
    }

    public function test_empty_inventory_has_neutral_chart_and_empty_states(): void
    {
        $this->staff();
        $this->get(route('dashboard'))->assertOk()->assertViewHas('warehouseProductCount',0)
            ->assertViewHas('warehousePriorityCount',0)->assertSee('background:#e8edeb;',false)
            ->assertSee('Tidak ada kondisi yang perlu perhatian.')->assertSee('Belum ada lokasi penyimpanan.');
    }
}
