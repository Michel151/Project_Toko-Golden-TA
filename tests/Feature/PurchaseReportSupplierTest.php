<?php

namespace Tests\Feature;

use App\Models\{Category, Product, PurchaseOrder, Supplier, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseReportSupplierTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $a;
    private Supplier $b;
    private Product $product;
    private array $period = ['from'=>'2026-10-01','to'=>'2026-10-23'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-23 10:00:00', 'Asia/Jakarta'));
        $this->actingAs(User::create(['name'=>'Owner','email'=>'purchase-filter@test.com','password'=>'password','role'=>'owner']));
        $this->a = Supplier::create(['name'=>'PT ABC']);
        $this->b = Supplier::create(['name'=>'CV Supplier Lain']);
        $category = Category::create(['name'=>'Barang Uji']);
        $unit = Unit::create(['name'=>'Pcs']);
        $this->product = Product::create(['name'=>'Barang Laporan','category_id'=>$category->id,'buy_unit_id'=>$unit->id,'buy_price'=>100,'sell_price_wholesale'=>200]);
        $root = $this->order('PO-A-PENDING', $this->a, 'pending', 200);
        $this->order('PO-A-COMPLETED', $this->a, 'completed', 600);
        $this->order('PO-B-PARTIAL', $this->b, 'partial', 1200);
        $this->order('PO-A-RETURN', $this->a, 'completed', 999, ['root_purchase_order_id'=>$root->id]);
        $this->order('PO-A-OLD', $this->a, 'pending', 500, ['order_date'=>'2026-09-30']);
    }

    private function order(string $number, Supplier $supplier, string $status, float $value, array $extra = []): PurchaseOrder
    {
        $order = PurchaseOrder::create($extra + ['po_number'=>$number,'supplier_id'=>$supplier->id,'order_date'=>'2026-10-23','status'=>$status,'created_by'=>auth()->id()]);
        $order->items()->create(['product_id'=>$this->product->id,'quantity_ordered'=>1,'buy_price'=>$value]);
        return $order;
    }

    private function capture(string $name, $page): void
    {
        if (getenv('CAPTURE_PURCHASE_SUPPLIER_UI') !== '1') return;
        $directory = storage_path('framework/testing/purchase-supplier');
        if (!is_dir($directory)) mkdir($directory, 0777, true);
        file_put_contents($directory.'/'.$name.'.html', $page->getContent());
    }

    public function test_default_all_suppliers_and_selected_supplier_scope_rows_and_kpis(): void
    {
        foreach ([[], ['supplier_id'=>'']] as $extra) {
            $this->get(route('report.purchase', $this->period + $extra))->assertOk()->assertViewHas('orderCount', 4)->assertViewHas('totalValue', 2000.0);
        }
        $page = $this->get(route('report.purchase', $this->period + ['supplier_id'=>$this->a->id]))->assertOk()
            ->assertViewHas('orderCount', 3)->assertViewHas('totalValue', 800.0)->assertViewHas('completed', 2)->assertViewHas('pending', 1)
            ->assertViewHas('orders', fn ($rows) => $rows->pluck('po_number')->all() === ['PO-A-RETURN','PO-A-COMPLETED','PO-A-PENDING'])
            ->assertDontSee('PO-B-PARTIAL')->assertDontSee('PO-A-OLD');
        $this->capture('selected', $page);
        $document = new \DOMDocument;
        @$document->loadHTML($page->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame((string) $this->a->id, $xpath->query('//select[@name="supplier_id"]/option[@selected]')->item(0)->getAttribute('value'));
        foreach (['pdf','excel'] as $format) {
            $href = $xpath->query('//a[contains(@href,"/purchase/'.$format.'")]')->item(0)->getAttribute('href');
            parse_str(parse_url($href, PHP_URL_QUERY), $query);
            $this->assertEquals($this->period + ['supplier_id'=>$this->a->id], $query);
        }
        $supplier = Supplier::create(['name'=>'Belum Ada PO']);
        $page = $this->get(route('report.purchase', $this->period + ['supplier_id'=>$supplier->id]))->assertOk()
            ->assertViewHas('orderCount', 0)->assertViewHas('totalValue', 0.0)->assertViewHas('completed', 0)->assertViewHas('pending', 0);
        $this->capture('empty', $page);
    }

    public function test_filtered_totals_and_pagination_cover_all_matching_orders(): void
    {
        for ($i = 1; $i <= 26; $i++) $this->order('PO-A-EXTRA-'.$i, $this->a, 'pending', 100);
        $filters = $this->period + ['supplier_id'=>$this->a->id,'per_page'=>25];
        $page = $this->get(route('report.purchase', $filters + ['page'=>2]))->assertOk()
            ->assertViewHas('orderCount', 29)->assertViewHas('totalValue', 3400.0)
            ->assertViewHas('orders', fn ($rows) => $rows->count() === 4 && $rows->total() === 29 && $rows->every(fn ($order) => $order->supplier_id === $this->a->id));
        parse_str(parse_url($page->viewData('orders')->url(1), PHP_URL_QUERY), $query);
        $this->assertEquals($filters + ['page'=>1], $query);
    }

    public function test_excel_export_uses_same_supplier_and_does_not_export_just_the_selected_page(): void
    {
        \Maatwebsite\Excel\Facades\Excel::fake();
        $this->get(route('report.purchase.excel', $this->period + ['supplier_id'=>$this->a->id,'page'=>2]))->assertOk();
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('Laporan-Pembelian-2026-10-01-sd-2026-10-23.xlsx', function ($export) {
            $data = $export->array();
            $rows = array_slice($data, 4);
            return $data[2] === ['Supplier: PT ABC'] && count($rows) === 3
                && array_unique(array_column($rows, 2)) === ['PT ABC'] && array_sum(array_column($rows, 6)) === 800.0;
        });
    }

    public function test_pdf_export_uses_same_supplier_and_keeps_return_orders_at_zero_value(): void
    {
        $pdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->once()->with('a4', 'portrait')->andReturnSelf();
        $pdf->shouldReceive('stream')->once()->andReturn(response('supplier-pdf'));
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->once()->with('report.pdf.purchase', \Mockery::on(function ($data) {
            return $data['supplierName'] === 'PT ABC' && $data['orders']->pluck('po_number')->all() === ['PO-A-RETURN','PO-A-COMPLETED','PO-A-PENDING']
                && $data['orders']->sum(fn ($order) => $order->totalValue()) === 800.0;
        }))->andReturn($pdf);
        $this->get(route('report.purchase.pdf', $this->period + ['supplier_id'=>$this->a->id]))->assertOk()->assertSee('supplier-pdf');
    }

    public function test_all_outputs_reject_invalid_supplier_ids(): void
    {
        foreach (['report.purchase','report.purchase.pdf','report.purchase.excel'] as $route) {
            foreach ([999999, 'invalid', ['invalid']] as $value) {
                $this->getJson(route($route, $this->period + ['supplier_id'=>$value]))->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
            }
        }
    }
}
