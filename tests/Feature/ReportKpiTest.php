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
        $period = ['from'=>'2026-10-01','to'=>'2026-10-17'];
        foreach ([
            [['from_location_id'=>$this->a->id], ['TRF-1']],
            [['to_location_id'=>$c->id], ['TRF-2']],
            [['from_location_id'=>$this->b->id,'to_location_id'=>$c->id], ['TRF-2']],
            [['from_location_id'=>$this->a->id,'to_location_id'=>$c->id], []],
            [['from_location_id'=>'','to_location_id'=>''], ['TRF-1','TRF-2']],
        ] as [$locations, $expected]) {
            $this->get(route('report.transfer', $period + $locations))->assertOk()
                ->assertViewHas('transfers', fn ($rows) => $rows->pluck('transfer_number')->sort()->values()->all() === $expected)
                ->assertViewHas('transferSummary', fn ($summary) => $summary['documents'] === count($expected));
            $this->get(route('inventory.transfers.index', $period + $locations))->assertOk()
                ->assertViewHas('transfers', fn ($rows) => $rows->pluck('transfer_number')->sort()->values()->all() === $expected);
        }
        $selected = $period + ['from_location_id'=>$this->b->id,'to_location_id'=>$c->id];
        $filtered = $this->get(route('report.transfer', $selected))->assertOk()
            ->assertViewHas('transferSummary',['documents'=>1,'products'=>2,'origins'=>1,'destinations'=>1]);
        $this->capture('transfer-filtered', $filtered);
        $document = new \DOMDocument;
        @$document->loadHTML($filtered->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame((string) $this->b->id, $xpath->query('//select[@name="from_location_id"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertSame((string) $c->id, $xpath->query('//select[@name="to_location_id"]/option[@selected]')->item(0)->getAttribute('value'));
        foreach (['pdf','excel'] as $format) {
            $href = $xpath->query('//a[contains(@href,"/transfer/'.$format.'")]')->item(0)->getAttribute('href');
            parse_str(parse_url($href, PHP_URL_QUERY), $query);
            $this->assertEquals($selected, $query);
        }

        \Maatwebsite\Excel\Facades\Excel::fake();
        $this->get(route('report.transfer.excel', $selected))->assertOk();
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('Laporan-Transfer-2026-10-01-sd-2026-10-17.xlsx', function ($export) {
            $rows = array_slice($export->array(), 4);
            return count($rows) === 2 && array_unique(array_column($rows, 0)) === ['TRF-2'];
        });
        $pdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->once()->with('a4', 'landscape')->andReturnSelf();
        $pdf->shouldReceive('stream')->once()->andReturn(response('filtered-pdf'));
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->once()
            ->with('report.pdf.transfer', \Mockery::on(fn ($data) => $data['transfers']->pluck('transfer_number')->all() === ['TRF-2']))
            ->andReturn($pdf);
        $this->get(route('report.transfer.pdf', $selected))->assertOk()->assertSee('filtered-pdf');
        $this->get(route('report.transfer',['from'=>'2026-10-17','to'=>'2026-10-17']))->assertOk()
            ->assertViewHas('transferSummary',['documents'=>1,'products'=>2,'origins'=>1,'destinations'=>1]);
        $page=$this->get(route('report.transfer',['from'=>'2026-11-01','to'=>'2026-11-30']))->assertOk()
            ->assertViewHas('transferSummary',['documents'=>0,'products'=>0,'origins'=>0,'destinations'=>0]);
        $this->capture('transfer-empty',$page);
        $this->assertDatabaseCount('stock_movements',0);
    }

    public function test_transfer_report_and_exports_sort_by_newest_date_creation_time_and_id(): void
    {
        foreach ([
            ['TRF-OLDER', '2026-10-15', '2026-10-17 10:00:00'],
            ['TRF-EARLY', '2026-10-16', '2026-10-16 08:00:00'],
            ['TRF-LATE', '2026-10-16', '2026-10-16 10:00:00'],
            ['TRF-TIE', '2026-10-16', '2026-10-16 10:00:00'],
        ] as [$number, $date, $created]) {
            $transfer = StockTransfer::create(['transfer_number'=>$number,'product_id'=>$this->normal->id,'quantity'=>1,
                'from_location_id'=>$this->a->id,'to_location_id'=>$this->b->id,'transfer_date'=>$date,'created_by'=>auth()->id()]);
            $transfer->forceFill(['created_at'=>$created])->save();
            $transfer->items()->create(['product_id'=>$this->normal->id,'quantity'=>1]);
        }
        $expected = ['TRF-TIE', 'TRF-LATE', 'TRF-EARLY', 'TRF-OLDER'];
        $filters = ['from'=>'2026-10-01','to'=>'2026-10-17','from_location_id'=>$this->a->id,'to_location_id'=>$this->b->id];
        $this->get(route('report.transfer', $filters))->assertOk()
            ->assertViewHas('transfers', fn ($rows) => $rows->pluck('transfer_number')->all() === $expected)
            ->assertViewHas('transferSummary', ['documents'=>4,'products'=>1,'origins'=>1,'destinations'=>1]);
        \Maatwebsite\Excel\Facades\Excel::fake();
        $this->get(route('report.transfer.excel', $filters))->assertOk();
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('Laporan-Transfer-2026-10-01-sd-2026-10-17.xlsx',
            fn ($export) => array_column(array_slice($export->array(), 4), 0) === $expected);
        $pdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->once()->with('a4', 'landscape')->andReturnSelf();
        $pdf->shouldReceive('stream')->once()->andReturn(response('sorted-pdf'));
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->once()->with('report.pdf.transfer',
            \Mockery::on(fn ($data) => $data['transfers']->pluck('transfer_number')->all() === $expected))->andReturn($pdf);
        $this->get(route('report.transfer.pdf', $filters))->assertOk()->assertSee('sorted-pdf');
    }

    public function test_transfer_location_filters_reject_invalid_locations_on_all_outputs(): void
    {
        foreach (['report.transfer','report.transfer.pdf','report.transfer.excel','inventory.transfers.index'] as $route) {
            foreach (['from_location_id','to_location_id'] as $field) {
                $this->getJson(route($route, [$field=>999999]))->assertUnprocessable()->assertJsonValidationErrors($field);
                $this->getJson(route($route, [$field=>['invalid']]))->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
    }

    public function test_transfer_report_keeps_many_items_outside_the_main_document_row(): void
    {
        $transfer = StockTransfer::create(['transfer_number'=>'TRF-MANY','product_id'=>$this->normal->id,'quantity'=>1,
            'from_location_id'=>$this->a->id,'to_location_id'=>$this->b->id,'transfer_date'=>'2026-10-17','created_by'=>auth()->id()]);
        for ($i = 1; $i <= 40; $i++) {
            $product = $this->normal->replicate();
            $product->name = 'Produk Transfer '.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $product->save();
            $transfer->items()->create(['product_id'=>$product->id,'quantity'=>$i]);
        }
        $second = StockTransfer::create(['transfer_number'=>'TRF-SINGLE','product_id'=>$this->normal->id,'quantity'=>2,
            'from_location_id'=>$this->b->id,'to_location_id'=>$this->a->id,'transfer_date'=>'2026-10-17','created_by'=>auth()->id()]);
        $second->items()->create(['product_id'=>$this->normal->id,'quantity'=>2]);
        $page = $this->get(route('report.transfer'))->assertOk()->assertSee('40 barang')->assertSee('1 barang');
        $document = new \DOMDocument;
        @$document->loadHTML($page->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(2, $xpath->query('//table[@id="transferReportTable"]/tbody/tr')->length);
        $this->assertSame(0, $xpath->query('//table[@id="transferReportTable"]//*[contains(text(),"Produk Transfer")]')->length);
        $this->assertSame(40, $xpath->query('//template[@id="transfer-items-'.$transfer->id.'"]//tr')->length);
        $this->assertStringContainsString('40 Pcs', $xpath->query('//template[@id="transfer-items-'.$transfer->id.'"]')->item(0)->textContent);
        $this->capture('transfer-many', $page);
        $index = $this->get(route('inventory.transfers.index'))->assertOk()->assertSee('40 barang')
            ->assertSee(route('inventory.transfers.show', $transfer));
        $document = new \DOMDocument;
        @$document->loadHTML($index->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(2, $xpath->query('//table[@id="transferListTable"]/tbody/tr')->length);
        $this->assertSame(0, $xpath->query('//table[@id="transferListTable"]//*[contains(text(),"Produk Transfer")]')->length);
        $this->assertSame(40, $xpath->query('//div[@id="transferTableWrap"]//template[@id="transfer-items-'.$transfer->id.'"]//tr')->length);
        $this->assertSame(1, $xpath->query('//div[@id="transferItemsModal"]')->length);
        $this->capture('transfer-index', $index);
        $filtered = $this->get(route('inventory.transfers.index', ['search'=>'Produk Transfer 40','from_location_id'=>$this->a->id,'to_location_id'=>$this->b->id]), ['X-Requested-With'=>'XMLHttpRequest'])
            ->assertOk()->assertViewHas('transfers', fn ($rows) => $rows->pluck('transfer_number')->all() === ['TRF-MANY']);
        $this->capture('transfer-index-filtered', $filtered);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_inventory_transfer_pagination_preserves_combined_location_and_existing_filters(): void
    {
        for ($i = 1; $i <= 22; $i++) {
            $transfer = StockTransfer::create(['transfer_number'=>'TRF-PAGE-'.$i,'product_id'=>$this->normal->id,'quantity'=>1,
                'from_location_id'=>$this->a->id,'to_location_id'=>$this->b->id,'transfer_date'=>'2026-10-17','created_by'=>auth()->id()]);
            $transfer->items()->create(['product_id'=>$this->normal->id,'quantity'=>1]);
        }
        $filters = ['from_location_id'=>$this->a->id,'to_location_id'=>$this->b->id,'product_id'=>$this->normal->id,
            'search'=>'TRF-PAGE','from'=>'2026-10-17','to'=>'2026-10-17'];
        $page = $this->get(route('inventory.transfers.index', $filters))->assertOk();
        $rows = $page->viewData('transfers');
        $this->assertSame(22, $rows->total());
        parse_str(parse_url($rows->url(2), PHP_URL_QUERY), $query);
        $this->assertEquals($filters + ['page'=>2], $query);
        $this->get(route('inventory.transfers.index', $filters + ['page'=>2]), ['X-Requested-With'=>'XMLHttpRequest'])->assertOk()
            ->assertViewHas('transfers', fn ($rows) => $rows->count() === 2 && $rows->total() === 22);
    }
}
