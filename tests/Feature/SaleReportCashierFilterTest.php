<?php

namespace Tests\Feature;

use App\Exports\ReportExport;
use App\Models\{Category, Product, Sale, Unit, User};
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Mockery;
use Tests\TestCase;

class SaleReportCashierFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $cashier;
    private User $other;
    private Product $product;
    private array $filters;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->user('Owner', 'owner');
        $this->cashier = $this->user('KasirA', 'kasir');
        $this->other = $this->user('KasirB', 'kasir');
        $unit = Unit::create(['name' => 'Botol']);
        $category = Category::create(['name' => 'Minuman']);
        $this->product = Product::create(['name' => 'Minuman Uji', 'category_id' => $category->id,
            'buy_unit_id' => $unit->id, 'buy_price' => 600, 'sell_price_wholesale' => 1000]);
        for ($i = 0; $i < 26; $i++) $this->sale($this->cashier, 1000, 600);
        $mixed = $this->sale($this->cashier, 700, 300, payment: 'transfer');
        $food = $this->product->replicate();
        $food->name = 'Makanan Uji';
        $food->category_id = Category::create(['name' => 'Makanan'])->id;
        $food->save();
        $mixed->items()->create(['product_id' => $food->id, 'quantity' => 1, 'sale_type' => 'ecer',
            'unit_price' => 1300, 'subtotal' => 1300, 'cost_subtotal' => 500]);
        $mixed->update(['total_amount' => 2000]);
        $this->sale($this->cashier, 9000, 600, status: 'pending');
        $this->sale($this->cashier, 5000, 600, date: '2026-09-30');
        $this->sale($this->other, 10000, 600);
        $this->sale($this->owner, 3000, 600);
        $this->filters = ['from' => '2026-10-01', 'to' => '2026-10-31',
            'cashier_id' => $this->cashier->id, 'category_id' => $category->id, 'page' => 2];
        $this->actingAs($this->owner);
    }

    private function user(string $name, string $role): User
    {
        return User::create(['name' => $name, 'email' => $name.'@example.com', 'password' => 'password', 'role' => $role]);
    }

    private function sale(User $user, int $revenue, int $cost, string $payment = 'tunai', string $status = 'paid', string $date = '2026-10-31'): Sale
    {
        $sale = Sale::create(['sale_number' => 'REPORT-'.$user->name.'-'.(Sale::count() + 1),
            'created_by' => $user->id, 'sale_date' => $date, 'status' => $status,
            'total_amount' => $revenue, 'payment_method' => $payment]);
        $sale->items()->create(['product_id' => $this->product->id, 'quantity' => 1, 'sale_type' => 'ecer',
            'unit_price' => $revenue, 'subtotal' => $revenue, 'cost_subtotal' => $cost]);
        return $sale;
    }

    public function test_owner_filter_applies_to_all_tabs_totals_links_and_combined_category(): void
    {
        $page = $this->get(route('report.sale', $this->filters))->assertOk()
            ->assertSee('id="saleReportCashier"', false)->assertSee('Semua Kasir')
            ->assertViewHas('totalTrx', 27)->assertViewHas('totalAmount', 26700)
            ->assertViewHas('totalTunai', 26000)->assertViewHas('totalTransfer', 700)
            ->assertViewHas('grossProfit', 10800.0)
            ->assertViewHas('sales', fn ($sales) => $sales->total() === 27 && $sales->count() === 2
                && $sales->every(fn ($sale) => $sale->created_by === $this->cashier->id))
            ->assertViewHas('topProducts', fn ($items) => $items->total() === 1
                && (float) $items->first()->total_amount === 26700.0 && (float) $items->first()->total_qty === 27.0)
            ->assertViewHas('productMargins', fn ($items) => $items->total() === 1 && $items->first()->cost === 15900.0)
            ->assertViewHas('cashiers', fn ($users) => $users->contains('id', $this->owner->id));
        foreach (['sales', 'topProducts', 'productMargins'] as $name) {
            parse_str(parse_url($page->viewData($name)->url(2), PHP_URL_QUERY), $query);
            $this->assertEquals($this->cashier->id, $query['cashier_id']);
            $this->assertEquals($this->product->category_id, $query['category_id']);
        }
        $document = new \DOMDocument;
        @$document->loadHTML($page->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(2, $xpath->query('//input[@type="hidden" and @name="cashier_id"]')->length);
        foreach (['report.sale.pdf', 'report.sale.excel'] as $route) {
            $this->assertSame(1, $xpath->query('//a[@href="'.route($route, $this->filters).'"]')->length);
        }
        $this->get(route('report.sale', array_replace($this->filters, ['cashier_id' => ''])))
            ->assertOk()->assertViewHas('totalAmount', 39700)->assertViewHas('totalTrx', 29);
        $this->get(route('report.sale', array_replace($this->filters, ['cashier_id' => $this->owner->id, 'page' => 1])))
            ->assertOk()->assertViewHas('totalAmount', 3000)->assertViewHas('totalTrx', 1);
    }

    public function test_pdf_exports_all_matching_sales_and_identifies_selected_cashier(): void
    {
        $document = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $document->shouldReceive('setPaper')->once()->with('a4', 'portrait')->andReturnSelf();
        $document->shouldReceive('stream')->once()->andReturn(response('PDF export'));
        Pdf::shouldReceive('loadView')->once()->withArgs(function ($view, $data) {
            $this->assertSame('report.pdf.sale', $view);
            $this->assertSame(27, $data['sales']->count());
            $this->assertEquals(26700, $data['sales']->sum('report_total_amount'));
            $this->assertTrue($data['sales']->every(fn ($sale) => $sale->created_by === $this->cashier->id));
            $html = view($view, $data)->render();
            $this->assertStringContainsString('Kasir: KasirA', $html);
            $this->assertStringContainsString('Rp 26.700', $html);
            $this->assertStringNotContainsString('REPORT-KasirB-', $html);
            return true;
        })->andReturn($document);
        $this->get(route('report.sale.pdf', $this->filters))->assertOk();
    }

    public function test_excel_exports_all_matching_rows_and_cashier_metadata(): void
    {
        Excel::shouldReceive('download')->once()->withArgs(function ($export, $filename) {
            $this->assertInstanceOf(ReportExport::class, $export);
            $data = $export->array();
            $this->assertStringContainsString('Kasir: KasirA', $data[2][0]);
            $this->assertStringContainsString('Kategori: Minuman', $data[2][0]);
            $rows = array_slice($data, 4);
            $this->assertCount(27, $rows);
            $this->assertSame(['KasirA'], array_values(array_unique(array_column($rows, 4))));
            $this->assertEquals(26700, array_sum(array_column($rows, 5)));
            return true;
        })->andReturn(response('Excel export'));
        $this->get(route('report.sale.excel', $this->filters))->assertOk();
    }

    public function test_owner_filter_validates_ids_on_screen_pdf_and_excel(): void
    {
        foreach (['report.sale', 'report.sale.pdf', 'report.sale.excel'] as $route) {
            foreach (['invalid', 999999] as $id) {
                $this->getJson(route($route, ['cashier_id' => $id]))
                    ->assertUnprocessable()->assertJsonValidationErrors('cashier_id');
            }
        }
        $empty = $this->user('KasirKosong', 'kasir');
        $this->get(route('report.sale', array_replace($this->filters, ['cashier_id' => $empty->id])))
            ->assertOk()->assertViewHas('totalTrx', 0)->assertViewHas('totalAmount', 0)
            ->assertViewHas('topProducts', fn ($items) => $items->total() === 0)
            ->assertViewHas('productMargins', fn ($items) => $items->total() === 0);
    }

    public function test_non_owner_does_not_receive_cashier_filter_or_user_list(): void
    {
        DB::table('role_permissions')->insert(['role' => 'kasir', 'permission' => 'report.sale.view']);
        $this->actingAs($this->other)->get(route('report.sale', $this->filters))->assertOk()
            ->assertDontSee('id="saleReportCashier"', false)
            ->assertViewHas('cashier', null)->assertViewHas('cashierId', null)
            ->assertViewHas('cashiers', fn ($users) => $users->isEmpty())
            ->assertViewHas('totalAmount', 39700);
    }
}
