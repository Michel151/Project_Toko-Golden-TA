<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, ProductUnit, Stock, StockBatch, StockTransfer, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportKpiTest extends TestCase
{
    use RefreshDatabase;

    private Location $a;
    private Location $b;
    private Category $category;
    private Product $normal;
    private Product $packed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-17 10:00:00','Asia/Jakarta'));
        $this->actingAs(User::create(['name'=>'Owner KPI','email'=>'kpi@example.com','password'=>'password','role'=>'owner']));
        $this->a=Location::create(['name'=>'Gudang A']);
        $this->b=Location::create(['name'=>'Toko B']);
        $this->category=Category::create(['name'=>'Kategori Kemasan']);
        $other=Category::create(['name'=>'Kategori Lain']);
        $unit=Unit::create(['name'=>'Pcs']);
        $make=fn($name,$price,$min,$category,$expires=false)=>Product::create(['name'=>$name,'category_id'=>$category->id,'buy_unit_id'=>$unit->id,'buy_price'=>$price,'sell_price_wholesale'=>2000,'min_stock_total'=>$min,'is_expirable'=>$expires]);
        $this->normal=$make('Barang Rendah',10,5,$other);
        $this->packed=$make('Barang Kemasan',999,0,$this->category);
        $expired=$make('Barang Kedaluwarsa',5,5,$this->category,true);
        $mixed=$make('Barang Campuran',3,7,$other,true);
        $empty=$make('Barang Kosong',10,0,$other);
        ProductUnit::create(['product_id'=>$this->packed->id,'unit_id'=>$unit->id,'conversion_to_base'=>1,'is_base_unit'=>true]);
        $box=Unit::create(['name'=>'Dus']);
        ProductUnit::create(['product_id'=>$this->packed->id,'unit_id'=>$box->id,'conversion_to_base'=>12,'buy_price'=>24,'is_default_purchase'=>true]);
        foreach ([[$this->normal,$this->a,3],[$this->packed,$this->b,24],[$expired,$this->a,10],[$mixed,$this->a,6],[$mixed,$this->b,4],[$empty,$this->a,0]] as [$product,$location,$qty]) {
            Stock::create(['product_id'=>$product->id,'location_id'=>$location->id,'quantity'=>$qty]);
        }
        foreach ([[$expired,$this->a,10,today()->subDay()],[$mixed,$this->a,6,today()->addMonths(2)],[$mixed,$this->b,4,today()->subDay()]] as [$product,$location,$qty,$date]) {
            StockBatch::create(['product_id'=>$product->id,'location_id'=>$location->id,'received_date'=>today()->subDays(10),'expired_date'=>$date,'quantity_initial'=>$qty,'quantity_remaining'=>$qty]);
        }
    }

    private function capture(string $name, $page): void
    {
        if (getenv('CAPTURE_REPORT_KPI_UI')!=='1') return;
        $directory=storage_path('framework/testing/report-kpis');
        if (!is_dir($directory)) mkdir($directory,0777,true);
        file_put_contents($directory.'/'.$name.'.html',$page->getContent());
    }

    public function test_stock_kpis_match_all_filtered_rows_and_separate_low_from_empty(): void
    {
        $page=$this->get(route('report.stock'))->assertOk()->assertViewHas('stockSummary',['products'=>5,'value'=>158.0,'low'=>2,'empty'=>2])
            ->assertSee('Nilai Persediaan')->assertSee('Stok Layak Jual Habis');
        $this->capture('stock',$page);
        $this->assertDatabaseCount('stock_movements',0);
    }

    public function test_stock_kpis_follow_category_expiry_and_existing_location_scope(): void
    {
        $this->get(route('report.stock',['category_id'=>$this->category->id]))->assertOk()
            ->assertViewHas('stockSummary',['products'=>2,'value'=>98.0,'low'=>0,'empty'=>1]);
        $this->get(route('report.stock',['expiry_status'=>'expired']))->assertOk()
            ->assertViewHas('stockSummary',['products'=>2,'value'=>80.0,'low'=>1,'empty'=>1]);
        // The existing report selects products by location but totals their stocks across all locations.
        $this->get(route('report.stock',['location_id'=>$this->b->id]))->assertOk()
            ->assertViewHas('stockSummary',['products'=>2,'value'=>78.0,'low'=>1,'empty'=>0]);
    }

    public function test_empty_stock_report_has_four_zero_metrics(): void
    {
        $category=Category::create(['name'=>'Belum Ada Barang']);
        $page=$this->get(route('report.stock',['category_id'=>$category->id]))->assertOk()
            ->assertViewHas('stockSummary',['products'=>0,'value'=>0.0,'low'=>0,'empty'=>0]);
        $this->capture('stock-empty',$page);
    }

    public function test_transfer_kpis_count_distinct_products_and_locations_within_the_period(): void
    {
        $c=Location::create(['name'=>'Gudang C']);
        foreach ([['TRF-1',$this->a,$this->b,'2026-10-16',[$this->normal]],['TRF-2',$this->b,$c,'2026-10-17',[$this->normal,$this->packed]],['TRF-LAMA',$this->a,$c,'2026-09-30',[$this->packed]]] as [$number,$from,$to,$date,$products]) {
            $transfer=StockTransfer::create(['transfer_number'=>$number,'product_id'=>$products[0]->id,'quantity'=>1,'from_location_id'=>$from->id,'to_location_id'=>$to->id,'transfer_date'=>$date,'created_by'=>auth()->id()]);
            foreach ($products as $product) $transfer->items()->create(['product_id'=>$product->id,'quantity'=>1]);
        }
        $page=$this->get(route('report.transfer',['from'=>'2026-10-01','to'=>'2026-10-17']))->assertOk()
            ->assertViewHas('transferSummary',['documents'=>2,'products'=>2,'origins'=>2,'destinations'=>2]);
        $this->capture('transfer',$page);
        $this->get(route('report.transfer',['from'=>'2026-10-17','to'=>'2026-10-17']))->assertOk()
            ->assertViewHas('transferSummary',['documents'=>1,'products'=>2,'origins'=>1,'destinations'=>1]);
        $page=$this->get(route('report.transfer',['from'=>'2026-11-01','to'=>'2026-11-30']))->assertOk()
            ->assertViewHas('transferSummary',['documents'=>0,'products'=>0,'origins'=>0,'destinations'=>0]);
        $this->capture('transfer-empty',$page);
        $this->assertDatabaseCount('stock_movements',0);
    }
}
