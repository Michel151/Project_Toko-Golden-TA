<?php

namespace Tests\Feature;

use App\Exports\ReportExport;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Stock;
use App\Models\StockBatch;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class StockReportUnitFormatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 6)->startOfDay());
        $this->actingAs(User::create([
            'name' => 'Owner',
            'email' => 'stock-report@example.com',
            'password' => 'password',
            'role' => 'owner',
        ]));

        $category = Category::create(['name' => 'Minuman']);
        $location = Location::create(['name' => 'Gudang']);
        $dus = Unit::create(['name' => 'Dus']);
        $botol = Unit::create(['name' => 'Botol']);
        $product = Product::create([
            'name' => 'Minuman Uji',
            'category_id' => $category->id,
            'buy_unit_id' => $dus->id,
            'buy_price' => 24000,
            'sell_price_wholesale' => 30000,
            'can_retail' => false,
            'conversion_factor' => 1,
            'min_stock_total' => 24,
            'is_expirable' => true,
        ]);
        foreach ([[$dus, 24, false], [$botol, 1, true]] as [$unit, $conversion, $isBase]) {
            ProductUnit::create([
                'product_id' => $product->id,
                'unit_id' => $unit->id,
                'conversion_to_base' => $conversion,
                'buy_price' => 1000 * $conversion,
                'sell_price' => 1250 * $conversion,
                'is_base_unit' => $isBase,
                'is_default_purchase' => ! $isBase,
                'is_default_sale' => ! $isBase,
            ]);
        }
        Stock::create(['product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 125]);
        foreach ([[96, '2026-10-01'], [5, '2026-10-05'], [24, '2026-10-06']] as [$quantity, $expiry]) {
            StockBatch::create([
                'product_id' => $product->id,
                'location_id' => $location->id,
                'received_date' => '2026-09-01',
                'expired_date' => $expiry,
                'quantity_initial' => $quantity,
                'quantity_remaining' => $quantity,
            ]);
        }
    }

    public function test_screen_and_pdf_format_expired_batches_in_product_units(): void
    {
        $response = $this->get(route('report.stock'));
        $response->assertOk()
            ->assertSee('5 Dus 5 Botol')
            ->assertSee('1 Dus')
            ->assertSee('4 Dus 5 Botol');

        $html = view('report.pdf.stock', $response->original->getData())->render();
        $this->assertMatchesRegularExpression('/<th\b[^>]*>Satuan Dasar<\/th>/', $html);
        $this->assertStringContainsString('<td>Botol</td>', $html);
        $this->assertStringContainsString('<td class="right low">4 Dus 5 Botol</td>', $html);
        $this->assertStringContainsString('<td class="right">5 Dus 5 Botol</td>', $html);
        $this->assertStringContainsString('<td class="right">1 Dus</td>', $html);

        $this->get(route('report.stock.pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_excel_uses_the_same_unit_breakdown_as_screen_and_pdf(): void
    {
        Excel::fake();

        $this->get(route('report.stock.excel'))->assertOk();

        Excel::assertDownloaded('Laporan-Stok-2026-10-06.xlsx', function (ReportExport $export) {
            $data = $export->array();
            $this->assertSame('Satuan Dasar', $data[3][2]);
            $this->assertSame([
                'Minuman Uji', 'Minuman', 'Botol', '1 Dus',
                '5 Dus 5 Botol', '1 Dus', '4 Dus 5 Botol', '1 Dus', 125000.0, 'Rendah',
            ], $data[4]);

            return true;
        });
    }

    public function test_fractional_stock_is_preserved_on_screen_pdf_and_excel(): void
    {
        Stock::query()->update(['quantity' => 125.5]);
        StockBatch::whereDate('expired_date', '2026-10-06')->update([
            'quantity_initial' => 24.5, 'quantity_remaining' => 24.5,
        ]);

        $response = $this->get(route('report.stock'))->assertOk()
            ->assertSee('5 Dus 5,5 Botol')->assertSee('1 Dus 0,5 Botol');
        $html = view('report.pdf.stock', $response->original->getData())->render();
        $this->assertStringContainsString('5 Dus 5,5 Botol', $html);
        $this->assertStringContainsString('1 Dus 0,5 Botol', $html);

        Excel::fake();
        $this->get(route('report.stock.excel'))->assertOk();
        Excel::assertDownloaded('Laporan-Stok-2026-10-06.xlsx', function (ReportExport $export) {
            $row = $export->array()[4];
            $this->assertSame('5 Dus 5,5 Botol', $row[4]);
            $this->assertSame('1 Dus 0,5 Botol', $row[5]);
            return true;
        });
    }

    public function test_pdf_keeps_all_location_columns_when_filtering_by_one_location(): void
    {
        $product = Product::firstOrFail();
        $gudang = Location::firstOrFail();
        $toko = Location::create(['name' => 'Toko']);
        Location::create(['name' => 'Lokasi Kosong']);
        Stock::create(['product_id' => $product->id, 'location_id' => $toko->id, 'quantity' => 48]);
        StockBatch::create([
            'product_id' => $product->id, 'location_id' => $toko->id,
            'received_date' => '2026-10-01', 'expired_date' => '2027-01-01',
            'quantity_initial' => 48, 'quantity_remaining' => 48,
        ]);

        $pdf = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->with('a4', 'landscape')->andReturnSelf();
        $pdf->shouldReceive('stream')->andReturn(response('stock-report'));
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->once()->with('report.pdf.stock', \Mockery::on(function ($data) {
            $html = view('report.pdf.stock', $data)->render();
            $document = new \DOMDocument();
            @$document->loadHTML($html);
            $xpath = new \DOMXPath($document);
            $columns = fn ($query) => array_map(fn ($node) => trim($node->textContent), iterator_to_array($xpath->query($query)));
            $this->assertSame(['Gudang', 'Lokasi Kosong', 'Toko', 'Total Fisik', 'Layak Jual'],
                array_slice($columns('//table//thead//th'), 3, 5));
            $this->assertSame(['1 Dus', '0 Botol', '2 Dus', '7 Dus 5 Botol', '3 Dus'],
                array_slice($columns('//table//tbody/tr[1]/td'), 3, 5));
            return true;
        }))->andReturn($pdf);
        $this->get(route('report.stock.pdf', ['location_id' => $gudang->id]))->assertOk();
    }
}
