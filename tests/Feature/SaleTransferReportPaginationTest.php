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
}
