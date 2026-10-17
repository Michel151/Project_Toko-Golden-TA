<?php

namespace Tests\Feature;

use App\Exports\GrossProfitExport;
use App\Models\{Category, Product, ProductUnit, Sale, SaleItem, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class GrossProfitReportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Product $product;
    private ProductUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-14 15:30:00','Asia/Jakarta'));
        $this->owner=User::create(['name'=>'Owner','email'=>'profit-owner@example.com','password'=>'password','role'=>'owner']);
        $category=Category::create(['name'=>'Kategori']);
        $unit=Unit::create(['name'=>'Dus']);
        $this->product=Product::create(['name'=>'Barang','category_id'=>$category->id,'buy_unit_id'=>$unit->id,'buy_price'=>999999,'sell_price_wholesale'=>10000,'is_active'=>true]);
        $this->unit=ProductUnit::create(['product_id'=>$this->product->id,'unit_id'=>$unit->id,'conversion_to_base'=>12,'buy_price'=>999999,'sell_price'=>10000,'is_base_unit'=>false,'is_default_purchase'=>true]);
        $this->actingAs($this->owner);
    }

    private function sale(string $number, string $date, string $status, float $revenue, ?float $cost): Sale
    {
        $sale=Sale::create(['sale_number'=>$number,'sale_date'=>$date,'sale_time'=>'15:30:00','status'=>$status,'customer_name'=>'=1+1','payment_method'=>'tunai','amount_paid'=>$revenue+10000,'total_amount'=>$revenue,'created_by'=>$this->owner->id]);
        if($cost!==null) SaleItem::create(['sale_id'=>$sale->id,'product_id'=>$this->product->id,'product_unit_id'=>$this->unit->id,'sale_type'=>'grosir','quantity'=>2,'unit_price'=>$revenue/2,'subtotal'=>$revenue,'cost_price'=>$cost/2,'cost_subtotal'=>$cost]);
        return $sale;
    }

    public function test_screen_pdf_and_excel_use_paid_sales_and_historical_costs(): void
    {
        $this->sale('INV-START','2026-10-01','paid',10000,6000);
        $this->sale('INV-END','2026-10-14','paid',5000,7000);
        $this->sale('INV-PENDING','2026-10-14','pending',50000,30000);
        $this->sale('INV-CANCELLED','2026-10-14','cancelled',50000,30000);
        $this->sale('INV-OUTSIDE','2026-09-30','paid',50000,30000);
        $response=$this->get(route('report.gross-profit'));
        $response->assertOk()->assertViewHas('totalRevenue',15000)->assertViewHas('totalCost',13000)
            ->assertViewHas('grossProfit',2000)->assertViewHas('transactionCount',2)
            ->assertViewHas('grossMargin',fn($value)=>abs($value-13.3333333333)<0.00001)
            ->assertSee('15:30:00 WIB')->assertDontSee('INV-PENDING')->assertDontSee('INV-CANCELLED')->assertDontSee('INV-OUTSIDE');
        $html=view('report.pdf.gross-profit',$response->original->getData())->render();
        $this->assertStringContainsString('Rp 2.000',$html);
        $this->assertStringContainsString('Rp -2.000',$html);
        $this->get(route('report.gross-profit.pdf'))->assertOk()->assertHeader('Content-Type','application/pdf');
        Excel::fake();
        $this->get(route('report.gross-profit.excel'))->assertOk();
        Excel::assertDownloaded('Laporan-Laba-Kotor-2026-10-01-sd-2026-10-14.xlsx',function(GrossProfitExport $export){
            $rows=$export->array();
            $this->assertSame(-2000.0,$rows[4][7]);
            $this->assertSame(2000.0,end($rows)[7]);
            $cell=(new Spreadsheet)->getActiveSheet()->getCell('A1');
            $export->bindValue($cell,'=1+1');
            $this->assertSame(DataType::TYPE_STRING,$cell->getDataType());
            $this->assertSame('=1+1',$cell->getValue());
            return true;
        });
    }

    public function test_missing_cost_or_missing_items_does_not_fabricate_profit(): void
    {
        $this->sale('INV-ZERO-HPP','2026-10-14','paid',10000,0);
        $this->sale('INV-NO-ITEMS','2026-10-14','paid',5000,null);
        $response=$this->get(route('report.gross-profit'));
        $response->assertOk()->assertViewHas('incompleteCount',2)->assertViewHas('grossProfit',null)
            ->assertViewHas('grossMargin',null)->assertSee('HPP belum lengkap')->assertSee('Belum lengkap');
        $html=view('report.pdf.gross-profit',$response->original->getData())->render();
        $this->assertStringContainsString('Total laba dan margin belum dapat dipastikan',$html);
        Excel::fake();
        $this->get(route('report.gross-profit.excel'))->assertOk();
        Excel::assertDownloaded('Laporan-Laba-Kotor-2026-10-01-sd-2026-10-14.xlsx',function(GrossProfitExport $export){
            $rows=$export->array();
            $this->assertNull($rows[4][7]);
            $this->assertNull(end($rows)[7]);
            return true;
        });
    }

    public function test_empty_zero_revenue_and_pagination_totals_are_safe(): void
    {
        $this->get(route('report.gross-profit'))->assertOk()->assertViewHas('grossProfit',0)->assertViewHas('grossMargin',null)->assertSee('Tidak ada nota lunas');
        $this->sale('INV-ZERO','2026-10-14','paid',0,500);
        $this->get(route('report.gross-profit'))->assertOk()->assertViewHas('grossProfit',-500)->assertViewHas('grossMargin',null);
        for($i=0;$i<26;$i++) $this->sale('INV-'.$i,'2026-10-14','paid',1000,500);
        $this->get(route('report.gross-profit',['page'=>2]))->assertOk()->assertViewHas('totalRevenue',26000)
            ->assertViewHas('totalCost',13500)->assertViewHas('grossProfit',12500)->assertViewHas('transactionCount',27)
            ->assertViewHas('sales',fn($sales)=>$sales->count()===2);
    }

    public function test_all_endpoints_validate_dates_and_enforce_report_permission(): void
    {
        foreach(['report.gross-profit','report.gross-profit.pdf','report.gross-profit.excel'] as $route){
            $this->get(route($route,['from'=>'wrong','to'=>'2026-10-14']))->assertSessionHasErrors('from');
            $this->get(route($route,['from'=>'2026-10-14','to'=>'2026-10-01']))->assertSessionHasErrors('to');
        }
        foreach(['kasir','staf_gudang'] as $role){
            $user=User::create(['name'=>$role,'email'=>$role.'-profit@example.com','password'=>'password','role'=>$role]);
            $this->actingAs($user);
            foreach(['report.gross-profit','report.gross-profit.pdf','report.gross-profit.excel'] as $route) $this->get(route($route))->assertForbidden();
        }
        DB::table('role_permissions')->insert(['role'=>'staf_gudang','permission'=>'report.gross-profit.view']);
        $this->get(route('report.gross-profit'))->assertOk()->assertDontSee('class="text-decoration-none fw-semibold"',false);
    }

    public function test_real_excel_output_preserves_header_positions_and_literal_customer_text(): void
    {
        $export=new GrossProfitExport('Laba Kotor',['Invoice','Tanggal','Waktu','Pelanggan','Kasir','Penjualan','HPP','Laba','Margin','HPP Lengkap'],
            [['INV-TEST','14/10/2026','15:30:00 WIB','=1+1','Owner',10000.0,6000.0,4000.0,'40,00%','Lengkap']], [6,7,8], [], 'Laba Kotor');
        $file=tempnam(sys_get_temp_dir(),'profit-export-');
        try {
            file_put_contents($file,Excel::raw($export,\Maatwebsite\Excel\Excel::XLSX));
            $sheet=\PhpOffice\PhpSpreadsheet\IOFactory::load($file)->getActiveSheet();
            $this->assertSame('Invoice',$sheet->getCell('A4')->getValue());
            $this->assertSame('=1+1',$sheet->getCell('D5')->getValue());
            $this->assertSame(DataType::TYPE_STRING,$sheet->getCell('D5')->getDataType());
            $this->assertEquals(4000,$sheet->getCell('H5')->getValue());
            $this->assertStringContainsString('Rp',$sheet->getStyle('H5')->getNumberFormat()->getFormatCode());
            $this->assertSame('A5',$sheet->getFreezePane());
        } finally {
            unlink($file);
        }
    }
}
