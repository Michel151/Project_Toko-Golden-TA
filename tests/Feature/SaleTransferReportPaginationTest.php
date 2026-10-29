<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, Sale, StockTransfer, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleTransferReportPaginationTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private Location $from;
    private Location $to;
    private array $period = ['from'=>'2026-10-01', 'to'=>'2026-10-28'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-28 10:00:00', 'Asia/Jakarta'));
        $this->actingAs(User::create(['name'=>'Owner Pagination','email'=>'pages@test.com','password'=>'password','role'=>'owner']));
        $category = Category::create(['name'=>'Kategori Uji']);
        $unit = Unit::create(['name'=>'Pcs']);
        $this->product = Product::create(['name'=>'Barang Pagination','category_id'=>$category->id,'buy_unit_id'=>$unit->id,'buy_price'=>2,'sell_price_wholesale'=>10]);
        $this->from = Location::create(['name'=>'Gudang']);
        $this->to = Location::create(['name'=>'Toko']);
    }

    private function capture(string $name, $response): void
    {
        if (getenv('CAPTURE_REPORT_PAGINATION_UI') !== '1') return;
        $directory = storage_path('framework/testing/report-pagination');
        if (!is_dir($directory)) mkdir($directory, 0777, true);
        file_put_contents($directory.'/'.$name.'.html', $response->getContent());
    }

    private function checkPages(string $report, string $variable, string $numberField, array $filters): void
    {
        $first = $this->get(route('report.'.$report, $filters))->assertOk()
            ->assertViewHas($variable, fn ($rows) => $rows->total() === 60 && $rows->count() === 25 && str_ends_with($rows->first()->$numberField, '060'));
        $this->capture($report, $first);
        $last = $this->get(route('report.'.$report, $filters + ['page'=>3,'per_page'=>25]))->assertOk()
            ->assertViewHas($variable, fn ($rows) => $rows->count() === 10 && str_ends_with($rows->first()->$numberField, '010'));
        $this->capture($report.'-last', $last);
        parse_str(parse_url($last->viewData($variable)->url(2), PHP_URL_QUERY), $query);
        $this->assertEquals($filters + ['page'=>2,'per_page'=>25], $query);
        foreach ([50=>50,100=>60] as $size=>$count) {
            $this->get(route('report.'.$report, $filters + ['per_page'=>$size]))->assertOk()
                ->assertViewHas($variable, fn ($rows) => $rows->count() === $count && $rows->perPage() === $size);
        }
        $empty = $this->get(route('report.'.$report, array_replace($filters, ['from'=>'2026-11-01','to'=>'2026-11-30'])))->assertOk()
            ->assertViewHas($variable, fn ($rows) => $rows->total() === 0);
        $this->capture($report.'-empty', $empty);
        foreach ([0,26,500,'invalid'] as $size) {
            $this->getJson(route('report.'.$report, $filters + ['per_page'=>$size]))->assertUnprocessable()->assertJsonValidationErrors('per_page');
        }
        \Maatwebsite\Excel\Facades\Excel::fake();
        $this->get(route('report.'.$report.'.excel', $filters + ['page'=>3,'per_page'=>25]))->assertOk();
        $filename = $report === 'sale' ? 'Laporan-Penjualan' : 'Laporan-Transfer';
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded($filename.'-2026-10-01-sd-2026-10-28.xlsx',
            fn ($export) => count(array_slice($export->array(), 4)) === 60);
        $pdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->once()->with('a4', $report === 'sale' ? 'portrait' : 'landscape')->andReturnSelf();
        $pdf->shouldReceive('stream')->once()->andReturn(response('complete-pdf'));
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->once()->with('report.pdf.'.$report,
            \Mockery::on(fn ($data) => $data[$variable]->count() === 60))->andReturn($pdf);
        $this->get(route('report.'.$report.'.pdf', $filters + ['page'=>3,'per_page'=>25]))->assertOk()->assertSee('complete-pdf');
    }

    public function test_sale_pagination_keeps_all_period_kpis_top_products_and_margins(): void
    {
        for ($i = 1; $i <= 61; $i++) {
            $sale = Sale::create(['sale_number'=>'INV-'.str_pad($i, 3, '0', STR_PAD_LEFT), 'sale_date'=>$i === 61 ? '2026-09-30' : '2026-10-28',
                'total_amount'=>10,'payment_method'=>$i % 2 ? 'tunai' : 'transfer','status'=>'paid','created_by'=>auth()->id()]);
            $sale->items()->create(['product_id'=>$this->product->id,'quantity'=>1,'unit_price'=>10,'subtotal'=>10,'cost_subtotal'=>2,'cost_price'=>2,'cost_method'=>'moving_average','sale_type'=>'ecer']);
        }
        foreach ([1,3] as $page) {
            $this->get(route('report.sale', $this->period + ['page'=>$page]))->assertOk()
                ->assertViewHas('totalTrx', 60)->assertViewHas('totalAmount', 600)->assertViewHas('totalTunai', 300)->assertViewHas('totalTransfer', 300)
                ->assertViewHas('grossProfit', 480.0)->assertViewHas('incompleteCostCount', 0)
                ->assertViewHas('topProducts', fn ($rows) => (float) $rows->first()->total_amount === 600.0)
                ->assertViewHas('productMargins', fn ($rows) => $rows->first()->revenue === 600.0 && $rows->first()->cost === 120.0);
        }
        $this->checkPages('sale', 'sales', 'sale_number', $this->period);
        $productsTab=$this->get(route('report.sale',$this->period+['tab'=>'products','page'=>3]))->assertOk()
            ->assertViewHas('activeTab','products')->assertViewHas('totalAmount',600)
            ->assertSee('id="saleProductsTab"',false)->assertSee('name="tab" id="saleReportActiveTab" value="products"',false);
        $this->capture('sale-products',$productsTab);
        parse_str(parse_url($productsTab->viewData('sales')->url(2),PHP_URL_QUERY),$tabQuery);
        $this->assertSame('products',$tabQuery['tab']);
        $marginsTab=$this->get(route('report.sale',$this->period+['tab'=>'margins','page'=>3]))->assertOk()
            ->assertViewHas('activeTab','margins')->assertViewHas('totalAmount',600)
            ->assertSee('name="tab" id="saleReportActiveTab" value="margins"',false);
        $this->capture('sale-margins',$marginsTab);
        $this->getJson(route('report.sale',$this->period+['tab'=>'unknown']))->assertUnprocessable()->assertJsonValidationErrors('tab');
    }

    public function test_transfer_pagination_keeps_location_filters_kpis_and_page_specific_item_details(): void
    {
        $other = Location::create(['name'=>'Gudang Lain']);
        for ($i = 1; $i <= 61; $i++) {
            $transfer = StockTransfer::create(['transfer_number'=>'TRF-'.str_pad($i, 3, '0', STR_PAD_LEFT),'transfer_date'=>'2026-10-28',
                'product_id'=>$this->product->id,'quantity'=>1,'from_location_id'=>$i === 61 ? $other->id : $this->from->id,
                'to_location_id'=>$this->to->id,'created_by'=>auth()->id()]);
            $transfer->items()->create(['product_id'=>$this->product->id,'quantity'=>1]);
        }
        $filters = $this->period + ['from_location_id'=>$this->from->id,'to_location_id'=>$this->to->id];
        foreach ([1,3] as $page) {
            $response = $this->get(route('report.transfer', $filters + ['page'=>$page]))->assertOk()
                ->assertViewHas('transferSummary', ['documents'=>60,'products'=>1,'origins'=>1,'destinations'=>1])->assertSee('60 Transfer')->assertDontSee('TRF-061');
            $document = new \DOMDocument;
            @$document->loadHTML($response->getContent());
            $xpath = new \DOMXPath($document);
            $this->assertSame($page === 1 ? 25 : 10, $xpath->query('//template[starts-with(@id,"transfer-items-")]')->length);
        }
        $this->checkPages('transfer', 'transfers', 'transfer_number', $filters);
    }

    public function test_all_sold_products_have_independent_pages_and_revenue_or_quantity_ranking(): void
    {
        $ids=[];
        for ($i=1;$i<=60;$i++) {
            $product=$this->product->replicate(); $product->name='Barang '.$i; $product->save(); $ids[]=$product->id;
            $sale=Sale::create(['sale_number'=>'TOP-'.$i,'sale_date'=>'2026-10-28','total_amount'=>$i*100,
                'payment_method'=>'tunai','status'=>'paid','created_by'=>auth()->id()]);
            $sale->items()->create(['product_id'=>$product->id,'quantity'=>61-$i,'cost_base_quantity'=>61-$i,
                'unit_price'=>100,'subtotal'=>$i*100,'cost_subtotal'=>10,'cost_method'=>'moving_average','sale_type'=>'ecer']);
        }
        $seen=[];
        foreach ([1,2,3] as $page) {
            $response=$this->get(route('report.sale',$this->period+['tab'=>'products','page'=>2,'products_page'=>$page]))->assertOk()
                ->assertViewHas('totalAmount',183000)->assertViewHas('sales',fn($rows)=>$rows->currentPage()===2&&$rows->count()===25)
                ->assertViewHas('topProducts',fn($rows)=>$rows->total()===60&&$rows->count()===($page===3?10:25)&&$rows->firstItem()===1+25*($page-1));
            $rows=$response->viewData('topProducts');
            array_push($seen,...$rows->pluck('product_id')->all());
            parse_str(parse_url($rows->url(2),PHP_URL_QUERY),$query);
            $this->assertSame('products',$query['tab']); $this->assertEquals(2,$query['page']);
            $this->assertEquals(2,$query['products_page']); $this->assertSame($this->period['from'],$query['from']);
            if ($page===1) $this->capture('sale-all-products',$response);
            if ($page===3) {
                $this->capture('sale-all-products-last',$response);
                $document=new \DOMDocument; @$document->loadHTML($response->getContent());
                $xpath=new \DOMXPath($document);
                $this->assertSame('51',trim($xpath->query('//table[@id="saleTopProductsTable"]/tbody/tr[1]/td[1]')->item(0)->textContent));
            }
        }
        $this->assertSame(array_reverse($ids),$seen);
        $marginIds=[];
        foreach ([1,2,3] as $page) {
            $marginResponse=$this->get(route('report.sale',$this->period+['tab'=>'margins','page'=>2,'products_page'=>2,'margins_page'=>$page]))->assertOk()
                ->assertViewHas('sales',fn($rows)=>$rows->currentPage()===2)
                ->assertViewHas('topProducts',fn($rows)=>$rows->currentPage()===2)
                ->assertViewHas('grossProfit',182400.0)
                ->assertViewHas('productMargins',fn($rows)=>$rows->total()===60&&$rows->count()===($page===3?10:25));
            $margins=$marginResponse->viewData('productMargins');
            array_push($marginIds,...$margins->pluck('product_id')->all());
            $this->assertEquals($margins->first()->revenue-10,$margins->first()->gross_profit);
            parse_str(parse_url($margins->url(3),PHP_URL_QUERY),$marginQuery);
            $this->assertEquals(2,$marginQuery['page']); $this->assertEquals(2,$marginQuery['products_page']);
            $this->assertEquals(3,$marginQuery['margins_page']); $this->assertSame('margins',$marginQuery['tab']);
            $this->capture('sale-all-margins'.($page===1?'':'-'.$page),$marginResponse);
        }
        $this->assertSame(array_reverse($ids),$marginIds);
        $quantity=$this->get(route('report.sale',$this->period+['tab'=>'products','products_sort'=>'quantity','page'=>2]))->assertOk()
            ->assertViewHas('topProducts',fn($rows)=>$rows->first()->product_id===$ids[0]&&(float)$rows->first()->total_qty===60.0);
        $this->capture('sale-products-quantity',$quantity);
        foreach ([50=>50,100=>60] as $size=>$count) {
            $this->get(route('report.sale',$this->period+['per_page'=>$size]))->assertOk()
                ->assertViewHas('topProducts',fn($rows)=>$rows->total()===60&&$rows->count()===$count)
                ->assertViewHas('productMargins',fn($rows)=>$rows->total()===60&&$rows->count()===$count);
        }
        $this->get(route('report.sale',['from'=>'2026-11-01','to'=>'2026-11-30','tab'=>'products']))->assertOk()
            ->assertViewHas('topProducts',fn($rows)=>$rows->total()===0)
            ->assertViewHas('productMargins',fn($rows)=>$rows->total()===0);
        $this->getJson(route('report.sale',['products_sort'=>'bad']))->assertUnprocessable()->assertJsonValidationErrors('products_sort');
        $this->getJson(route('report.sale',['products_page'=>-1]))->assertUnprocessable()->assertJsonValidationErrors('products_page');
        $this->getJson(route('report.sale',['margins_page'=>-1]))->assertUnprocessable()->assertJsonValidationErrors('margins_page');
    }

    public function test_quantity_ranking_uses_historical_base_snapshots_then_legacy_unit_conversions(): void
    {
        $sale=Sale::create(['sale_number'=>'UNITS','sale_date'=>'2026-10-28','total_amount'=>1400,'payment_method'=>'tunai','status'=>'paid','created_by'=>auth()->id()]);
        $productIds=[];
        foreach ([['Snapshot',500,2,20,100],['Sumber',500,3,null,50],['Satuan Lama',300,2,null,10],['Legacy',100,2,null,12]] as [$name,$revenue,$qty,$snapshot,$conversion]) {
            $product=$this->product->replicate(); $product->name=$name; $product->can_retail=true; $product->conversion_factor=$conversion; $product->retail_unit_id=$product->buy_unit_id; $product->save();
            $productIds[]=$product->id;
            $unit=null;
            if ($name!=='Legacy') $unit=\App\Models\ProductUnit::create(['product_id'=>$product->id,'unit_id'=>$product->buy_unit_id,'conversion_to_base'=>$conversion,'buy_price'=>1,'sell_price'=>1]);
            $item=$sale->items()->create(['product_id'=>$product->id,'product_unit_id'=>$unit?->id,'quantity'=>$qty,'cost_base_quantity'=>$snapshot,
                'unit_price'=>1,'subtotal'=>$revenue,'cost_subtotal'=>1,'cost_method'=>'moving_average','sale_type'=>'grosir']);
            if ($name==='Sumber') {
                $item->sources()->create(['location_id'=>$this->from->id,'quantity'=>1,'base_quantity'=>2]);
                $item->sources()->create(['location_id'=>$this->to->id,'quantity'=>2,'base_quantity'=>3]);
            }
        }
        $quantity=$this->get(route('report.sale',$this->period+['tab'=>'products','products_sort'=>'quantity']))->assertOk()->viewData('topProducts');
        $this->assertSame([$productIds[3],$productIds[0],$productIds[2],$productIds[1]],$quantity->pluck('product_id')->all());
        $this->assertEquals([24,20,20,5],$quantity->pluck('total_qty')->all());
        $revenue=$this->get(route('report.sale',$this->period))->assertOk()->viewData('topProducts');
        $this->assertSame($productIds,$revenue->pluck('product_id')->all());
        $pending=Sale::create(['sale_number'=>'NOT-PAID','sale_date'=>'2026-10-28','total_amount'=>9000,'payment_method'=>'tunai','status'=>'pending','created_by'=>auth()->id()]);
        $pending->items()->create(['product_id'=>$this->product->id,'quantity'=>9000,'unit_price'=>1,'subtotal'=>9000,'sale_type'=>'ecer']);
        $this->get(route('report.sale',$this->period))->assertOk()->assertViewHas('topProducts',fn($rows)=>$rows->total()===4);
        $this->assertDatabaseCount('stock_movements',0);
    }
}
