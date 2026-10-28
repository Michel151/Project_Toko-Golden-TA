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
}
