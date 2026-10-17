<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, ProductLocation, Stock, StockBatch, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExpiryMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private Location $shop;
    private Location $warehouse;
    private Unit $unit;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-16 13:00:00', 'Asia/Jakarta'));
        $this->shop = Location::create(['name'=>'Toko']);
        $this->warehouse = Location::create(['name'=>'Gudang']);
        $this->unit = Unit::create(['name'=>'Botol']);
        $this->category = Category::create(['name'=>'Minuman']);
        $this->actingAs(User::create(['name'=>'Kasir','email'=>'expiry@example.com','password'=>'password','role'=>'kasir']));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(string $name, float $minimum = 5, bool $tracked = true): Product
    {
        return Product::create(['name'=>$name,'category_id'=>$this->category->id,'buy_unit_id'=>$this->unit->id,
            'buy_price'=>1000,'sell_price_wholesale'=>1500,'min_stock_total'=>$minimum,'is_expirable'=>$tracked]);
    }

    private function batch(Product $product, string $code, ?string $expiry, float $qty, ?Location $location = null): StockBatch
    {
        $location ??= $this->shop;
        $stock = Stock::firstOrCreate(['product_id'=>$product->id,'location_id'=>$location->id],['quantity'=>0]);
        $stock->increment('quantity', $qty);
        ProductLocation::firstOrCreate(['product_id'=>$product->id,'location_id'=>$location->id],['min_stock'=>2]);
        return StockBatch::create(['product_id'=>$product->id,'location_id'=>$location->id,'batch_code'=>$code,
            'received_date'=>'2026-09-01','expired_date'=>$expiry,'quantity_initial'=>$qty,'quantity_remaining'=>$qty]);
    }

    public function test_physical_and_sellable_totals_status_filters_and_counters_agree(): void
    {
        $expired = $this->product('Hanya Kedaluwarsa');
        $this->batch($expired,'EXPIRED','2026-10-15',100);
        $low = $this->product('Layak Rendah');
        $this->batch($low,'OLD','2026-10-01',30);
        $this->batch($low,'FRESH','2027-01-01',2);
        $safe = $this->product('Batch Aman');
        $this->batch($safe,'SAFE','2027-01-01',20);
        $untracked = $this->product('Tanpa Pelacakan',5,false);
        Stock::create(['product_id'=>$untracked->id,'location_id'=>$this->shop->id,'quantity'=>12]);

        $response = $this->get(route('inventory.stock.index'))->assertOk()
            ->assertSee('Fisik / Layak Jual')->assertSee('1 rincian aman')->assertDontSee('Tidak ada rincian kedaluwarsa');
        $this->assertSame(1, $response->viewData('outOfStockCount'));
        $this->assertSame(1, $response->viewData('lowStockCount'));
        $this->assertSame(['physical'=>32.0,'sellable'=>2.0,'expired'=>30.0],$low->fresh()->stockAvailability());
        $this->assertSame(12.0, $untracked->stockAvailability()['sellable']);
        $this->get(route('inventory.stock.index',['status'=>'out']))->assertOk()->assertSee('Hanya Kedaluwarsa')->assertDontSee('Layak Rendah');
        $this->get(route('inventory.stock.index',['status'=>'low']))->assertOk()->assertSee('Layak Rendah')->assertDontSee('Hanya Kedaluwarsa');
        $this->get(route('inventory.stock.index',['status'=>'safe']))->assertOk()->assertSee('Batch Aman')->assertDontSee('Layak Rendah');
    }

    public function test_priority_is_ordered_scoped_and_excludes_empty_and_safe_batches(): void
    {
        $product = $this->product('Prioritas');
        $this->batch($product,'NEAR','2026-10-23',4);
        $this->batch($product,'EXPIRED','2026-10-15',3);
        $this->batch($product,'TODAY','2026-10-16',2);
        $this->batch($product,'DAY30','2026-11-15',2);
        $this->batch($product,'DAY31','2026-11-16',2);
        $this->batch($product,'EMPTY','2026-10-01',0);
        $this->batch($product,'UNDATED',null,1);
        $this->batch($product,'OTHER-LOCATION','2026-10-10',5,$this->warehouse);
        $response=$this->get(route('inventory.stock.index',['view'=>'priorities','location_id'=>$this->shop->id]))
            ->assertOk()->assertSeeInOrder(['EXPIRED','TODAY','NEAR','DAY30','UNDATED'])
            ->assertSee('Lewat 1 hari')->assertSee('Kedaluwarsa hari ini')->assertSee('Sisa 7 hari')
            ->assertSee('Tanggal belum dicatat')->assertSee('Pisahkan dari rak')->assertSee('Dahulukan pengambilan')
            ->assertDontSee('DAY31')->assertDontSee('EMPTY')->assertDontSee('OTHER-LOCATION');
        $this->assertSame(5,$response->viewData('priorityCount'));
        $this->get(route('inventory.stock.index',['view'=>'priorities','expiry_status'=>'expired']))
            ->assertOk()->assertSee('EXPIRED')->assertDontSee('NEAR');
        $this->get(route('inventory.stock.index',['view'=>'priorities','search'=>'Tidak Ada']))
            ->assertOk()->assertSee('Tidak ada rincian kedaluwarsa yang perlu ditindaklanjuti')->assertDontSee('EXPIRED');
        $this->get(route('inventory.stock.index',['view'=>'priorities','expiry_status'=>'safe']))
            ->assertOk()->assertSee('Tidak ada rincian kedaluwarsa yang perlu ditindaklanjuti');
    }

    public function test_day_boundary_and_undated_batches_match_existing_sale_rules(): void
    {
        $product=$this->product('Batas Hari');
        $this->batch($product,'YESTERDAY','2026-10-15',3);
        $today=$this->batch($product,'TODAY','2026-10-16',4);
        $undated=$this->batch($product,'UNDATED',null,2);
        $this->assertSame(6.0,$product->stockAvailability()['sellable']);
        $this->assertSame('Kedaluwarsa hari ini',$today->expiry_countdown);
        $this->assertSame('Tanggal belum dicatat',$undated->expiry_countdown);
        Carbon::setTestNow('2026-10-17 00:01:00');
        $this->assertSame(2.0,$product->stockAvailability()['sellable']);
        $this->assertSame('Lewat 1 hari',$today->expiry_countdown);
    }

    public function test_cashier_card_shows_expired_physical_stock_without_claiming_it_is_sellable(): void
    {
        $product=$this->product('Batch Kartu');
        $this->batch($product,'OLD','2026-10-10',8);
        $this->get(route('inventory.stock-card.show',['product'=>$product,'tab'=>'expiry']))
            ->assertOk()->assertSee('Stok Fisik')->assertSee('Layak Jual')->assertSee('Lewat 6 hari')->assertSee('Jual: 0 Botol');
        $this->get(route('inventory.transfers.create'))->assertForbidden();
        $this->get(route('inventory.stock.index',['view'=>'priorities']))->assertOk()->assertDontSee('Transfer Barang');
    }

    public function test_priority_pagination_keeps_filters_and_counts_all_matches(): void
    {
        $product=$this->product('Banyak Batch');
        for($i=0;$i<27;$i++) $this->batch($product,sprintf('B-%02d',$i),'2026-10-15',1);
        $response=$this->get(route('inventory.stock.index',['view'=>'priorities','location_id'=>$this->shop->id]));
        $response->assertOk();
        $this->assertSame(27,$response->viewData('priorityCount'));
        $this->assertCount(25,$response->viewData('priorityBatches'));
        $this->get(route('inventory.stock.index',['view'=>'priorities','location_id'=>$this->shop->id,'page'=>2]))
            ->assertOk()->assertSee('B-25')->assertSee('B-26')->assertDontSee('B-00');
    }

    public function test_warehouse_and_owner_details_share_batch_summary_and_countdown(): void
    {
        $product=$this->product('Detail Gudang');
        $this->batch($product,'LOT-A','2026-10-15',5);
        $this->batch($product,'LOT-B','2026-10-15',4);
        $warehouse=User::create(['name'=>'Gudang','email'=>'warehouse-expiry@example.com','password'=>'password','role'=>'staf_gudang']);
        $this->actingAs($warehouse)->get(route('inventory.stock.index',['view'=>'priorities']))
            ->assertOk()->assertSee('LOT-A')->assertSee('LOT-B');
        $this->get(route('inventory.stock-card.show',['product'=>$product,'tab'=>'stock']))
            ->assertOk()->assertSee('Jual: 0 Botol')->assertSee('Rendah');
        $owner=User::create(['name'=>'Owner','email'=>'owner-expiry@example.com','password'=>'password','role'=>'owner']);
        $this->actingAs($owner)->get(route('master.products.show',['product'=>$product,'tab'=>'expiry']))
            ->assertOk()->assertSee('Stok Fisik')->assertSee('Lewat 1 hari')->assertSee('Jual: 0 Botol')
            ->assertDontSee('ID Batch')->assertDontSee('LOT-A')->assertDontSee('LOT-B');
        $this->assertDatabaseHas('stock_batches', ['product_id'=>$product->id, 'batch_code'=>'LOT-A']);
        $this->assertDatabaseHas('stock_batches', ['product_id'=>$product->id, 'batch_code'=>'LOT-B']);
    }
}
