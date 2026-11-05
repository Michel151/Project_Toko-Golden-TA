<?php

namespace Tests\Feature;

use App\Models\{Category, Product, ProductUnit, Sale, Unit, User};
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class SaleTabExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_and_margin_exports_match_all_filtered_pages_and_selected_sort(): void
    {
        $owner = User::create(['name'=>'Owner', 'email'=>'tab-export@test.com', 'password'=>'password', 'role'=>'owner']);
        $cashier = User::create(['name'=>'Kasir Terpilih', 'email'=>'tab-export-cashier@test.com', 'password'=>'password', 'role'=>'kasir']);
        $this->actingAs($owner);
        $category = Category::create(['name'=>'Kategori Terpilih']);
        $otherCategory = Category::create(['name'=>'Kategori Lain']);
        $pcs = Unit::create(['name'=>'Pcs']);
        $pack = Unit::create(['name'=>'Pak']);
        $box = Unit::create(['name'=>'Dus']);
        $makeSale = function ($user, string $date, string $status = 'paid') {
            return Sale::create(['sale_number'=>'EXPORT-'.(Sale::count()+1), 'sale_date'=>$date, 'status'=>$status,
                'total_amount'=>100000, 'payment_method'=>'tunai', 'created_by'=>$user->id]);
        };
        $sale = $makeSale($cashier, '2026-11-04');
        for ($i=0; $i<28; $i++) {
            $product = Product::create(['name'=>'Barang '.str_pad($i,2,'0',STR_PAD_LEFT), 'category_id'=>$category->id,
                'buy_unit_id'=>$box->id, 'buy_price'=>1000, 'sell_price_wholesale'=>2000, 'is_active'=>true]);
            $base = ProductUnit::create(['product_id'=>$product->id, 'unit_id'=>$pcs->id, 'conversion_to_base'=>1, 'is_base_unit'=>true]);
            ProductUnit::create(['product_id'=>$product->id, 'unit_id'=>$pack->id, 'conversion_to_base'=>6]);
            ProductUnit::create(['product_id'=>$product->id, 'unit_id'=>$box->id, 'conversion_to_base'=>12, 'is_default_sale'=>true]);
            $sale->items()->create(['product_id'=>$product->id, 'product_unit_id'=>$base->id, 'sale_type'=>'ecer',
                'quantity'=>19+$i, 'cost_base_quantity'=>19+$i, 'unit_price'=>100,
                'subtotal'=>$i===27 ? 0 : 10000+$i*100, 'cost_subtotal'=>$i>=26 ? 0 : 12000-$i*100, 'cost_method'=>$i===26 ? 'legacy_master' : 'moving_average']);
        }
        // These rows must not leak into any export, regardless of the page requested.
        foreach ([[$owner,'2026-11-04','paid',$category], [$cashier,'2026-10-01','paid',$category],
            [$cashier,'2026-11-04','pending',$category], [$cashier,'2026-11-04','paid',$otherCategory]] as [$user,$date,$status,$cat]) {
            $excluded = Product::create(['name'=>'Excluded '.Product::count(), 'category_id'=>$cat->id, 'buy_unit_id'=>$pcs->id,
                'buy_price'=>1000, 'sell_price_wholesale'=>2000]);
            $makeSale($user,$date,$status)->items()->create(['product_id'=>$excluded->id, 'quantity'=>1000, 'sale_type'=>'ecer', 'unit_price'=>100, 'subtotal'=>100000]);
        }
        $filters = ['from'=>'2026-11-01', 'to'=>'2026-11-05', 'category_id'=>$category->id, 'cashier_id'=>$cashier->id,
            'page'=>9, 'products_page'=>2, 'margins_page'=>2, 'per_page'=>25];
        foreach (['products'=>['revenue','quantity'], 'margins'=>['revenue','margin_desc','margin_asc','profit_desc','profit_asc']] as $tab=>$sorts) {
            foreach ($sorts as $sort) {
                $params = $filters + ['tab'=>$tab, ($tab==='products' ? 'products_sort' : 'margins_sort')=>$sort];
                $variable = $tab==='products' ? 'topProducts' : 'productMargins';
                $first = $this->get(route('report.sale', array_replace($params, ['products_page'=>1, 'margins_page'=>1])))->assertOk()->viewData($variable);
                $second = $this->get(route('report.sale', $params))->assertOk()->viewData($variable);
                $all = $first->getCollection()->concat($second->getCollection())->values();
                $this->assertCount(28, $all);
                $expected = $tab==='products'
                    ? $all->map(fn ($row,$index)=>[$index+1,$row->product->name,$row->display_quantity,(float)$row->total_amount])->all()
                    : $all->map(fn ($row)=>[$row->name,$row->revenue,$row->cost ?? 'Belum lengkap',$row->gross_profit ?? 'Belum lengkap',
                        $row->cost === null ? 'Belum lengkap' : ($row->margin_percentage ?? '—')])->all();
                Excel::fake();
                $prefix = $tab==='products' ? 'Laporan-Produk-Terlaris' : 'Laporan-Margin-per-Produk';
                $this->get(route('report.sale.excel',$params))->assertOk();
                Excel::assertDownloaded($prefix.'-2026-11-01-sd-2026-11-05.xlsx', function ($export) use ($expected) {
                    $rows = $export->array();
                    $this->assertSame($expected, array_slice($rows,4));
                    $this->assertStringContainsString('Kategori: Kategori Terpilih', $rows[2][0]);
                    $this->assertStringContainsString('Kasir: Kasir Terpilih', $rows[2][0]);
                    return true;
                });
                $pdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
                $pdf->shouldReceive('setPaper')->once()->with('a4',$tab==='margins' ? 'landscape' : 'portrait')->andReturnSelf();
                $pdf->shouldReceive('stream')->once()->with($prefix.'-2026-11-01-sd-2026-11-05.pdf')->andReturn(response('tab-pdf'));
                Pdf::shouldReceive('loadView')->once()->with('report.pdf.sale-products', \Mockery::on(function ($data) use ($expected) {
                    if ($data['rows'] !== $expected) return false;
                    $html = view('report.pdf.sale-products',$data)->render();
                    return str_contains($html,'Barang 00') && str_contains($html,'Barang 27') && !str_contains($html,'Excluded');
                }))->andReturn($pdf);
                $this->get(route('report.sale.pdf',$params))->assertOk()->assertSee('tab-pdf');
            }
        }
    }

    public function test_export_tabs_validate_input_and_handle_empty_results(): void
    {
        $this->actingAs(User::create(['name'=>'Owner','email'=>'empty-tab-export@test.com','password'=>'password','role'=>'owner']));
        foreach (['report.sale.pdf','report.sale.excel'] as $route) {
            $this->getJson(route($route,['tab'=>'unknown']))->assertUnprocessable();
            $this->getJson(route($route,['tab'=>'products','products_sort'=>'unknown']))->assertUnprocessable();
            $this->getJson(route($route,['tab'=>'margins','margins_sort'=>'unknown']))->assertUnprocessable();
        }
        foreach (['products','margins'] as $tab) {
            $this->get(route('report.sale.pdf',['tab'=>$tab,'from'=>'2026-01-01','to'=>'2026-01-31','products_page'=>99]))
                ->assertOk()->assertHeader('Content-Type','application/pdf');
            Excel::fake();
            $this->get(route('report.sale.excel',['tab'=>$tab,'from'=>'2026-01-01','to'=>'2026-01-31','products_page'=>99]))->assertOk();
            $prefix = $tab==='products' ? 'Laporan-Produk-Terlaris' : 'Laporan-Margin-per-Produk';
            Excel::assertDownloaded($prefix.'-2026-01-01-sd-2026-01-31.xlsx', fn ($export)=>count($export->array())===4);
        }
    }
}
