<?php

namespace Tests\Feature;

use App\Models\{Category, Product, ProductUnit, Sale, SaleItem, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleReportGrossProfitTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private ProductUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Jakarta')->setDate(2026,10,25)->setTime(12,0));
        $this->actingAs(User::create(['name'=>'Owner','email'=>'sale-report@test.com','password'=>'password','role'=>'owner']));
        $category = Category::create(['name'=>'Minuman']);
        $unit = Unit::create(['name'=>'Botol']);
        $this->product = Product::create(['name'=>'Produk Uji','category_id'=>$category->id,'buy_unit_id'=>$unit->id,
            'buy_price'=>999999,'sell_price_wholesale'=>10000,'is_active'=>true]);
        $this->unit = ProductUnit::create(['product_id'=>$this->product->id,'unit_id'=>$unit->id,'conversion_to_base'=>1,'is_base_unit'=>true]);
    }

    private function sale(string $status, string $date, int $revenue, int $cost, string $method = 'legacy_master'): Sale
    {
        $sale = Sale::create(['sale_number'=>'INV-REPORT-'.(Sale::count()+1),'created_by'=>auth()->id(),
            'sale_date'=>$date,'status'=>$status,'total_amount'=>$revenue,'payment_method'=>'tunai']);
        SaleItem::create(['sale_id'=>$sale->id,'product_id'=>$this->product->id,'product_unit_id'=>$this->unit->id,
            'quantity'=>1,'sale_type'=>'ecer','unit_price'=>$revenue,'subtotal'=>$revenue,'cost_price'=>$cost,
            'cost_subtotal'=>$cost,'cost_method'=>$method]);
        return $sale;
    }

    private function report()
    {
        return $this->get(route('report.sale',['from'=>'2026-10-20','to'=>'2026-10-25']))->assertOk();
    }

    public function test_margin_sort_orders_all_products_before_pagination_and_keeps_unknown_values_last(): void
    {
        $ids = [];
        for ($i = 1; $i <= 30; $i++) {
            $product = $this->product->replicate();
            $product->name = 'Margin '.$i;
            $product->save();
            $ids[] = $product->id;
            $sale = $this->sale('paid', '2026-10-21', 1000 + $i * 10, 1300 - $i * 30);
            $sale->items()->update(['product_id'=>$product->id, 'product_unit_id'=>null]);
        }
        $unknown = $this->sale('paid', '2026-10-21', 20000, 0);
        $filters = ['from'=>'2026-10-20', 'to'=>'2026-10-25', 'tab'=>'margins',
            'category_id'=>$this->product->category_id, 'products_sort'=>'quantity'];
        foreach (['margin_desc', 'profit_desc', 'margin_asc', 'profit_asc'] as $sort) {
            $expected = str_ends_with($sort, '_desc') ? array_reverse($ids) : $ids;
            $response = $this->get(route('report.sale', $filters + ['margins_sort'=>$sort]))->assertOk()
                ->assertViewHas('activeTab', 'margins')->assertViewHas('marginsSort', $sort)
                ->assertSee('Margin Tertinggi (%)')->assertSee('Laba Kotor Terendah (Rp)');
            $rows = $response->viewData('productMargins');
            $this->assertSame(31, $rows->total());
            $this->assertSame(array_slice($expected, 0, 25), $rows->pluck('product_id')->all());
            parse_str(parse_url($rows->url(2), PHP_URL_QUERY), $query);
            $this->assertSame($sort, $query['margins_sort']);
            $this->assertEquals($filters['category_id'], $query['category_id']);
            $second = $this->get($rows->url(2))->assertOk()->viewData('productMargins');
            $this->assertSame(array_merge(array_slice($expected, 25), [$this->product->id]), $second->pluck('product_id')->all());
            $this->assertNull($second->last()->gross_profit);
        }
        $this->get(route('report.sale', $filters))->assertOk()
            ->assertViewHas('marginsSort', 'revenue')
            ->assertViewHas('productMargins', fn ($rows) => $rows->first()->product_id === $this->product->id);
        $this->getJson(route('report.sale', ['margins_sort'=>'invalid']))->assertUnprocessable();
        $this->assertSame('0.00', $unknown->items()->first()->cost_subtotal);
    }

    public function test_category_filter_uses_only_matching_lines_in_mixed_invoices_and_exports(): void
    {
        $mixed=$this->sale('paid','2026-10-21',10000,6000);
        $foodCategory=Category::create(['name'=>'Makanan']);
        $food=$this->product->replicate(); $food->name='Biskuit'; $food->category_id=$foodCategory->id; $food->save();
        $mixed->items()->create(['product_id'=>$food->id,'quantity'=>1,'sale_type'=>'ecer','unit_price'=>3000,'subtotal'=>3000,'cost_subtotal'=>0]);
        $mixed->update(['total_amount'=>13000]);
        $transfer=$this->sale('paid','2026-10-22',5000,2000,'moving_average'); $transfer->update(['payment_method'=>'transfer']);
        $foodSale=$this->sale('paid','2026-10-23',4000,1000); $foodSale->items()->update(['product_id'=>$food->id]);
        $this->sale('pending','2026-10-23',90000,1);
        $filters=['from'=>'2026-10-20','to'=>'2026-10-25','category_id'=>$this->product->category_id,'tab'=>'products'];
        $response=$this->get(route('report.sale',$filters))->assertOk()
            ->assertViewHas('totalTrx',2)->assertViewHas('totalAmount',15000)->assertViewHas('totalTunai',10000)
            ->assertViewHas('totalTransfer',5000)->assertViewHas('grossProfit',7000.0)->assertViewHas('incompleteCostCount',0)
            ->assertViewHas('topProducts',fn($rows)=>$rows->total()===1&&(float)$rows->first()->total_amount===15000.0)
            ->assertViewHas('productMargins',fn($rows)=>$rows->total()===1&&$rows->first()->cost===8000.0)
            ->assertSee('Total Kategori')->assertSee('nota campuran');
        $mixedReport=$response->viewData('sales')->firstWhere('id',$mixed->id);
        $this->assertEquals(13000,$mixedReport->total_amount);
        $this->assertEquals(10000,$mixedReport->report_total_amount);
        $this->assertCount(1,$mixedReport->items);
        $this->assertEquals(13000,$mixed->fresh()->total_amount);
        $this->assertSame(2,$mixed->items()->count());
        foreach (['sales','topProducts','productMargins'] as $variable) {
            parse_str(parse_url($response->viewData($variable)->url(2),PHP_URL_QUERY),$query);
            $this->assertEquals($this->product->category_id,$query['category_id']);
        }
        $all=$this->get(route('report.sale',array_replace($filters,['category_id'=>''])))->assertOk()
            ->assertViewHas('totalAmount',22000)->assertViewHas('totalTrx',3);
        if (getenv('CAPTURE_SALE_PROFIT_UI')==='1') {
            $dir=storage_path('framework/testing/sale-profit'); if (!is_dir($dir)) mkdir($dir,0777,true);
            file_put_contents($dir.'/category-filtered.html',$response->getContent());
            file_put_contents($dir.'/category-all.html',$all->getContent());
        }
        \Maatwebsite\Excel\Facades\Excel::fake();
        $this->get(route('report.sale.excel',$filters+['page'=>9]))->assertOk();
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('Laporan-Penjualan-2026-10-20-sd-2026-10-25.xlsx',function($export) {
            $rows=$export->array();
            return str_contains($rows[2][0],'Minuman')&&$rows[3][5]==='Total Kategori'
                &&count(array_slice($rows,4))===2&&array_sum(array_column(array_slice($rows,4),5))===15000.0;
        });
        $pdf=\Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->once()->with('a4','portrait')->andReturnSelf();
        $pdf->shouldReceive('stream')->once()->andReturn(response('category-pdf'));
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->once()->with('report.pdf.sale',\Mockery::on(function($data) {
            $html=view('report.pdf.sale',$data)->render();
            return $data['sales']->count()===2&&$data['category']->name==='Minuman'
                &&str_contains($html,'Rp 15.000')&&!str_contains($html,'Rp 13.000')&&str_contains($html,'Total Kategori');
        }))->andReturn($pdf);
        $this->get(route('report.sale.pdf',$filters+['page'=>9]))->assertOk()->assertSee('category-pdf');
        $this->assertDatabaseCount('stock_movements',0);
    }

    public function test_category_filter_validates_all_endpoints_and_handles_categories_without_sales(): void
    {
        $empty=Category::create(['name'=>'Belum Terjual']);
        $this->get(route('report.sale',['category_id'=>$empty->id]))->assertOk()
            ->assertViewHas('totalTrx',0)->assertViewHas('totalAmount',0)->assertViewHas('grossProfit',0.0)
            ->assertViewHas('topProducts',fn($rows)=>$rows->total()===0)
            ->assertViewHas('productMargins',fn($rows)=>$rows->total()===0);
        foreach (['report.sale','report.sale.pdf','report.sale.excel'] as $name) {
            $this->getJson(route($name,['category_id'=>999999]))->assertUnprocessable()->assertJsonValidationErrors('category_id');
        }
    }

    public function test_gross_profit_uses_only_paid_sales_in_range_and_historical_item_costs(): void
    {
        $this->sale('paid','2026-10-20',10000,6000);
        $this->sale('paid','2026-10-25',5000,2000,'moving_average');
        $this->sale('paid','2026-10-19',99999,1);
        $this->sale('paid','2026-10-26',99999,1);
        $this->sale('pending','2026-10-21',99999,1);
        $this->sale('cancelled','2026-10-21',99999,1);
        $page = $this->report()->assertViewHas('totalAmount',15000)->assertViewHas('grossProfit',7000.0)
            ->assertViewHas('incompleteCostCount',0)->assertSee('Laba Kotor')->assertSee('Rp 7.000')
            ->assertViewHas('productMargins',fn ($rows) => $rows->count() === 1 && $rows->first()->revenue === 15000.0
                && $rows->first()->cost === 8000.0 && $rows->first()->gross_profit === 7000.0 && $rows->first()->margin_percentage === 46.67)
            ->assertSee('Margin per Produk')->assertSee('46,67%');
        if (getenv('CAPTURE_SALE_PROFIT_UI') === '1') {
            $directory = storage_path('framework/testing/sale-profit');
            if (!is_dir($directory)) mkdir($directory,0777,true);
            file_put_contents($directory.'/report.html',$page->getContent());
        }
        $this->product->update(['buy_price'=>1]);
        $this->report()->assertViewHas('grossProfit',7000.0);
    }

    public function test_incomplete_costs_and_missing_items_do_not_display_a_misleading_profit(): void
    {
        $this->sale('paid','2026-10-21',10000,0);
        $this->sale('paid','2026-10-21',10000,-1);
        $withoutItems = $this->sale('paid','2026-10-21',10000,5000);
        $withoutItems->items()->delete();
        $this->report()->assertViewHas('grossProfit',null)->assertViewHas('incompleteCostCount',3)
            ->assertViewHas('productMargins',fn ($rows) => $rows->first()->cost === null && $rows->first()->margin_percentage === null)
            ->assertSee('Belum lengkap')->assertSee('3 transaksi dengan HPP belum lengkap');
    }

    public function test_verified_zero_moving_average_cost_and_negative_profit_are_supported(): void
    {
        $this->sale('paid','2026-10-21',10000,0,'moving_average');
        $this->report()->assertViewHas('grossProfit',10000.0)->assertViewHas('incompleteCostCount',0);
        $this->sale('paid','2026-10-21',5000,20000);
        $this->report()->assertViewHas('grossProfit',-5000.0)->assertSee('Rp -5.000')->assertSee('-33,33%');
    }

    public function test_transaction_table_has_natural_height_and_keeps_pagination(): void
    {
        for ($i = 0; $i < 30; $i++) $this->sale('paid','2026-10-21',10000,6000);
        $page = $this->report()->assertViewHas('sales', fn ($rows) => $rows->count() === 25 && $rows->total() === 30)
            ->assertSee('id="saleTransactionsTable"', false)->assertDontSee('height:640px', false)->assertDontSee('max-height:600px', false)
            ->assertDontSee('Daftar transaksi penjualan, gulir untuk melihat transaksi lainnya');
        $document = new \DOMDocument;
        @$document->loadHTML($page->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(25, $xpath->query('//table[@id="saleTransactionsTable"]/tbody/tr')->length);
        $this->assertGreaterThan(0, $xpath->query('//div[contains(@class,"card-footer")]//a[contains(@href,"page=2")]')->length);
    }

    public function test_empty_period_displays_zero_gross_profit(): void
    {
        $this->report()->assertViewHas('grossProfit',0.0)->assertViewHas('incompleteCostCount',0)->assertSee('Rp 0')
            ->assertViewHas('productMargins',fn ($rows) => $rows->isEmpty())->assertSee('Tidak ada penjualan produk pada periode ini.');
    }

    public function test_product_margin_covers_every_sold_product_and_isolates_missing_costs(): void
    {
        $this->sale('paid','2026-10-21',10000,0);
        $completeIds = [];
        for ($i = 1; $i <= 11; $i++) {
            $product = $this->product->replicate();
            $product->name = 'Produk Lengkap '.$i;
            $product->save();
            $unit = $this->unit->replicate();
            $unit->product_id = $product->id;
            $unit->save();
            $sale = $this->sale('paid','2026-10-21',5000,3000);
            $sale->items()->update(['product_id'=>$product->id,'product_unit_id'=>$unit->id]);
            $completeIds[] = $product->id;
        }
        $this->report()->assertViewHas('grossProfit',null)->assertViewHas('productMargins',function ($rows) use ($completeIds) {
            return $rows->count() === 12
                && $rows->firstWhere('product_id',$this->product->id)->margin_percentage === null
                && $rows->whereIn('product_id',$completeIds)->every(fn ($row) => $row->gross_profit === 2000.0 && $row->margin_percentage === 40.0);
        });
    }

    public function test_zero_product_revenue_has_no_percentage_instead_of_dividing_by_zero(): void
    {
        $this->sale('paid','2026-10-21',0,500);
        $this->report()->assertViewHas('productMargins',fn ($rows) => $rows->first()->gross_profit === -500.0
            && $rows->first()->margin_percentage === null)->assertSee('Rp -500');
    }
    public function test_package_sort_and_unit_filter_use_main_sale_unit_before_pagination(): void
    {
        $box = Unit::create(['name'=>'Dus']);
        ProductUnit::create(['product_id'=>$this->product->id, 'unit_id'=>$box->id, 'conversion_to_base'=>12, 'is_default_sale'=>true]);
        $sale = $this->sale('paid','2026-10-21',100000,500);
        // Historical quantity remains usable even when a legacy item has no unit reference.
        $sale->items()->update(['product_unit_id'=>null, 'quantity'=>999, 'cost_base_quantity'=>27]);
        for ($i=0; $i<26; $i++) {
            $product=$this->product->replicate(); $product->name='Botol '.$i; $product->save();
            $unit=$this->unit->replicate(); $unit->product_id=$product->id; $unit->is_default_sale=true; $unit->save();
            $other=$this->sale('paid','2026-10-21',1000,500);
            $other->items()->update(['product_id'=>$product->id,'product_unit_id'=>$unit->id,'quantity'=>10]);
        }
        $params=['from'=>'2026-10-20','to'=>'2026-10-25','tab'=>'products'];
        $this->get(route('report.sale',$params))->assertOk()->assertViewHas('productsSort','revenue')
            ->assertViewHas('topProducts',fn($rows)=>$rows->first()->product_id===$this->product->id);
        $this->get(route('report.sale',$params+['products_sort'=>'quantity']))->assertOk()
            ->assertViewHas('topProducts',fn($rows)=>$rows->total()===27 && $rows->first()->package_quantity===10.0);
        $this->get(route('report.sale',$params+['products_sort'=>'quantity','products_unit'=>$box->id]))->assertOk()
            ->assertViewHas('topProducts',fn($rows)=>$rows->total()===1 && $rows->first()->package_quantity===2.25
                && $rows->first()->display_quantity==='2 Dus 3 Botol')
            ->assertSee('2 Dus 3 Botol');
        $this->get(route('report.sale',$params+['products_sort'=>'quantity','products_unit'=>$this->unit->unit_id,'products_page'=>2]))->assertOk()
            ->assertViewHas('topProducts',fn($rows)=>$rows->total()===26 && $rows->count()===1 && str_contains($rows->url(1),'products_unit='));
        $this->getJson(route('report.sale',$params+['products_unit'=>999999]))->assertUnprocessable();
    }

}
