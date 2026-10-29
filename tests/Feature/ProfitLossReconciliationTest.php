<?php

namespace Tests\Feature;

use App\Models\{Category, Expense, InventoryCostBalance, Location, Product, ProductUnit, PurchaseOrder, PurchaseReceipt, PurchaseReceiptItem, Sale, SaleItem, Stock, StockOpname, Supplier, Unit, User};
use App\Services\{MovingAverageCostService, StockService};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ProfitLossReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private ProductUnit $unit;
    private Location $location;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-10-01');
        $this->owner = User::create(['name' => 'Owner Uji', 'email' => 'profit-recon@test.com', 'password' => 'password', 'role' => 'owner']);
        $this->actingAs($this->owner);
        $category = Category::create(['name' => 'Barang Uji']);
        $unit = Unit::create(['name' => 'Botol']);
        $this->location = Location::create(['name' => 'Toko']);
        $this->product = Product::create(['name' => 'Barang Uji Moving Average', 'category_id' => $category->id,
            'buy_unit_id' => $unit->id, 'buy_price' => 999999, 'sell_price_wholesale' => 65000, 'is_active' => true]);
        $this->unit = ProductUnit::create(['product_id' => $this->product->id, 'unit_id' => $unit->id, 'conversion_to_base' => 1,
            'buy_price' => 50000, 'sell_price' => 65000, 'is_base_unit' => true, 'is_default_purchase' => true, 'is_default_sale' => true]);
    }

    private function at(string $date): void { $this->travelTo(Carbon::parse($date.' 10:00:00', 'Asia/Jakarta')); }

    private function receive(float $quantity, int $price, string $date = '2026-10-01'): void
    {
        $this->at($date);
        $supplier = Supplier::firstOrCreate(['name' => 'Supplier Uji']);
        $po = PurchaseOrder::create(['po_number' => 'PO/UJI/'.(PurchaseOrder::count() + 1), 'supplier_id' => $supplier->id,
            'order_date' => $date, 'status' => 'pending', 'created_by' => $this->owner->id]);
        $item = $po->items()->create(['product_id' => $this->product->id, 'product_unit_id' => $this->unit->id,
            'quantity_ordered' => $quantity, 'buy_price' => $price]);
        $receipt = PurchaseReceipt::create(['purchase_order_id' => $po->id, 'receipt_date' => $date, 'received_by' => $this->owner->id]);
        $line = PurchaseReceiptItem::create(['purchase_receipt_id' => $receipt->id, 'purchase_order_item_id' => $item->id,
            'product_id' => $this->product->id, 'product_unit_id' => $this->unit->id, 'location_id' => $this->location->id, 'quantity_received' => $quantity]);
        app(StockService::class)->receiveStockUsingUnit($this->product, $this->unit, $this->location, $quantity, $receipt, $date, $po->po_number, null, $line);
    }

    private function sell(float $quantity, string $date = '2026-10-02', string $status = 'paid'): Sale
    {
        $this->at($date);
        $this->post(route('sale.sales.store'), ['sale_status' => $status, 'sale_date' => $date, 'transaction_confirmed' => 1,
            'payment_method' => 'tunai', 'amount_paid' => $status === 'paid' ? 10000000 : 0,
            'items' => [['product_id' => $this->product->id, 'product_unit_id' => $this->unit->id, 'quantity' => $quantity, 'unit_price' => 65000,
                'sources' => [['location_id' => $this->location->id, 'quantity' => $quantity]]]],
        ])->assertSessionHasNoErrors()->assertSessionMissing('error');
        return Sale::latest('id')->firstOrFail();
    }

    private function adjust(float $quantity, string $date, ?string $unitCost = null): void
    {
        $this->at($date);
        $opname = StockOpname::create(['opname_number' => 'OPN/UJI/'.(StockOpname::count() + 1), 'location_id' => $this->location->id,
            'opname_date' => $date, 'status' => 'completed', 'created_by' => $this->owner->id]);
        app(StockService::class)->adjustStock($this->product, $this->location, $quantity, $opname, $date, $opname->opname_number, $unitCost);
    }

    private function expense(string $category = 'salary', string $amount = '10000', string $status = 'confirmed'): void
    {
        Expense::create(['recognized_date' => '2026-10-15', 'category' => $category, 'description' => 'Beban uji '.$category,
            'amount' => $amount, 'status' => $status, 'created_by' => $this->owner->id, 'submission_token' => (string) str()->uuid()]);
    }

    private function report(array $filters = [])
    {
        return $this->get(route('report.profit-loss', $filters ?: ['month' => '2026-10']))->assertOk();
    }

    private function line(array $reconciliation, string $label): mixed
    {
        return collect($reconciliation['lines'])->first(fn ($line) => $line[0] === $label)[1];
    }

    private function capturePage(string $name, $response): void
    {
        if (getenv('CAPTURE_PL_REPORTS') !== '1') return;
        $directory = storage_path('framework/testing/profit-loss');
        if (!is_dir($directory)) mkdir($directory, 0777, true);
        file_put_contents($directory.'/'.$name.'.html', $response->getContent());
    }

    public function test_report_sections_and_reconciliation_match_the_two_purchase_example(): void
    {
        $this->receive(20, 50000);
        $this->sell(3);
        $this->receive(20, 55000, '2026-10-03');
        $this->expense();
        $response = $this->report()->assertViewHas('revenue', 195000.0)->assertViewHas('cost', 150000.0)
            ->assertViewHas('grossProfit', 45000.0)->assertViewHas('operatingProfit', 35000.0)
            ->assertSeeInOrder(['PENDAPATAN', 'Harga Pokok Penjualan', 'BEBAN OPERASIONAL', 'Laba (Rugi) Usaha', 'PENDAPATAN DAN BEBAN LAINNYA', 'Pendapatan dan Beban Lainnya Neto', 'Laba (Rugi) Sebelum Pajak', 'Beban Pajak Penghasilan', 'Laba (Rugi) Bersih', 'Rekonsiliasi Persediaan']);
        $recon = $response->viewData('reconciliation');
        $this->assertSame('matched', $recon['status']);
        $this->assertSame(0.0, $recon['opening']);
        $this->assertSame(1950000.0, $recon['closing']);
        $this->assertSame(1950000.0, $recon['calculatedClosing']);
        $this->assertSame(0.0, $recon['difference']);
        $this->assertSame(2100000.0, $this->line($recon, 'Penerimaan pembelian'));
        $this->assertSame(-150000.0, $this->line($recon, 'HPP penjualan moving average'));
    }

    public function test_opening_and_closing_are_historical_not_current_balance_or_master_price(): void
    {
        $this->receive(10, 10000, '2026-09-30');
        $this->receive(10, 12000, '2026-10-01');
        $sale = $this->sell(5);
        $this->receive(10, 20000, '2026-11-01');
        $this->unit->update(['buy_price' => 999999]);
        $recon = $this->report()->viewData('reconciliation');
        $this->assertSame(100000.0, $recon['opening']);
        $this->assertSame(165000.0, $recon['closing']);
        $this->assertSame(0.0, $recon['difference']);
        $this->assertEquals(365000, InventoryCostBalance::first()->inventory_value);
        $this->assertEquals(55000, $sale->fresh()->items->first()->cost_subtotal);
    }

    public function test_import_is_separate_from_purchases_and_not_report_income(): void
    {
        app(StockService::class)->initializeImportedStock($this->product, $this->location, 20, $this->product);
        $this->sell(3);
        $response = $this->report()->assertViewHas('revenue', 195000.0)->assertViewHas('grossProfit', 45000.0);
        $recon = $response->viewData('reconciliation');
        $this->assertSame(0.0, $this->line($recon, 'Penerimaan pembelian'));
        $this->assertSame(1000000.0, $this->line($recon, 'Stok masuk melalui impor'));
        $this->assertSame(850000.0, $recon['closing']);
        $this->assertSame('matched', $recon['status']);
    }

    public function test_opname_losses_and_gains_are_separate_and_not_charged_twice(): void
    {
        $this->receive(20, 50000);
        $this->sell(3);
        $this->adjust(15, '2026-10-03');
        $this->adjust(18, '2026-10-04', '60000');
        $this->sell(3, '2026-10-05');
        $this->expense('salary', '20000');
        $response = $this->report()->assertViewHas('cost', 305000.0)->assertViewHas('grossProfit', 85000.0)
            ->assertViewHas('operatingProfit', 145000.0);
        $expected = ['Harga Pokok Penjualan' => -305000.0, 'Laba (Rugi) Usaha' => 65000.0,
            'Pendapatan Selisih Lebih Persediaan' => 180000.0, 'Beban Selisih Kurang Persediaan' => -100000.0,
            'Pendapatan dan Beban Lainnya Neto' => 80000.0, 'Laba (Rugi) Sebelum Pajak' => 145000.0];
        foreach ($expected as $label => $value) $this->assertSame($value, $this->line(['lines' => $response->viewData('lines')], $label));
        $response->assertDontSee('Kerugian Selisih Persediaan')->assertDontSee('Keuntungan Selisih Persediaan')
            ->assertDontSee('Total Pendapatan Lain-lain')->assertDontSee('Total Beban Lain-lain');
        $before = DB::table('inventory_cost_entries')->orderBy('id')->get()->toJson();
        $excel = $this->get(route('report.profit-loss.excel', ['month' => '2026-10']))->assertOk();
        $book = IOFactory::load($excel->baseResponse->getFile()->getPathname());
        $rows = collect($book->getSheet(0)->toArray(null, false, false, false))->mapWithKeys(fn ($row) => [$row[0] ?? '' => $row[1] ?? null]);
        foreach ($expected as $label => $value) $this->assertEquals($value, $rows[$label]);
        $this->assertFalse($rows->has('Total Pendapatan Lain-lain'));
        $this->assertFalse($rows->has('Total Beban Lain-lain'));
        $book->disconnectWorksheets();
        $pdf = $this->get(route('report.profit-loss.pdf', ['month' => '2026-10']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame($before, DB::table('inventory_cost_entries')->orderBy('id')->get()->toJson());
        $this->capturePage('opname', $response);
        if (getenv('CAPTURE_PL_REPORTS') === '1') file_put_contents(storage_path('framework/testing/profit-loss/opname.pdf'), $pdf->getContent());
        $recon = $response->viewData('reconciliation');
        $this->assertSame(-100000.0, $this->line($recon, 'Penyesuaian opname berkurang'));
        $this->assertSame(180000.0, $this->line($recon, 'Penyesuaian opname bertambah'));
        $this->assertSame(775000.0, $recon['closing']);
        $this->assertSame(0.0, $recon['difference']);
        $this->assertSame('matched', $recon['status']);
    }

    public function test_reservations_and_transfers_keep_owned_inventory_value_without_creating_hpp(): void
    {
        $this->receive(10, 10000);
        $this->sell(3, '2026-10-02', 'pending');
        $warehouse = Location::create(['name' => 'Gudang']);
        app(StockService::class)->transferStock($this->product, $this->location, $warehouse, 2, movementDate: '2026-10-02');
        $response = $this->report()->assertViewHas('cost', 0.0)->assertViewHas('revenue', 0.0);
        $recon = $response->viewData('reconciliation');
        $this->assertSame(100000.0, $recon['closing']);
        $this->assertSame(100000.0, $this->line($recon, 'Penerimaan pembelian'));
        $this->assertSame('matched', $recon['status']);
    }

    public function test_legacy_only_inventory_is_unavailable_not_a_zero_valued_stock(): void
    {
        Stock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 10]);
        $response = $this->report()->assertSee('Belum tersedia')->assertSee('tidak dianggap nol');
        $recon = $response->viewData('reconciliation');
        $this->assertSame('unavailable', $recon['status']);
        $this->assertNull($recon['opening']);
        $this->assertNull($recon['closing']);
        $this->assertNull($recon['difference']);
        $this->assertSame(1, $recon['legacyProducts']);
        $this->capturePage('unavailable', $response);
    }

    public function test_mixed_legacy_and_moving_average_values_have_explicit_partial_coverage(): void
    {
        $legacy = $this->product->replicate();
        $legacy->name = 'Barang Lama';
        $legacy->save();
        Stock::create(['product_id' => $legacy->id, 'location_id' => $this->location->id, 'quantity' => 10]);
        $this->receive(10, 10000);
        $this->sell(3);
        $sale = Sale::create(['sale_number' => 'INV-LAMA', 'sale_date' => '2026-10-02', 'status' => 'paid',
            'payment_method' => 'tunai', 'total_amount' => 2000, 'created_by' => $this->owner->id]);
        SaleItem::create(['sale_id' => $sale->id, 'product_id' => $legacy->id, 'quantity' => 1, 'unit_price' => 2000,
            'subtotal' => 2000, 'cost_price' => 1000, 'cost_subtotal' => 1000, 'sale_type' => 'grosir']);
        $before = SaleItem::orderBy('id')->get()->toJson();
        $response = $this->report()->assertViewHas('cost', 31000.0)->assertDontSee('HPP penjualan historis');
        $hpp = collect($response->viewData('lines'))->filter(fn ($line) => $line[0] === 'Harga Pokok Penjualan');
        $this->assertCount(1, $hpp);
        $this->assertSame(-31000.0, $hpp->first()[1]);
        $this->assertNotContains('HPP penjualan moving average', array_column($response->viewData('lines'), 0));
        $excel = $this->get(route('report.profit-loss.excel', ['month' => '2026-10']))->assertOk();
        $book = IOFactory::load($excel->baseResponse->getFile()->getPathname());
        $mainRows = collect($book->getSheet(0)->toArray(null, false, false, false));
        $this->assertCount(1, $mainRows->filter(fn ($row) => $row[0] === 'Harga Pokok Penjualan'));
        $this->assertEquals(-31000, $mainRows->first(fn ($row) => $row[0] === 'Harga Pokok Penjualan')[1]);
        $this->assertNotContains('HPP penjualan historis', $mainRows->pluck(0)->all());
        $book->disconnectWorksheets();
        $this->assertSame($before, SaleItem::orderBy('id')->get()->toJson());
        $recon = $response->viewData('reconciliation');
        $this->assertSame('partial', $recon['status']);
        $this->assertSame(70000.0, $recon['closing']);
        $this->assertSame(-30000.0, $this->line($recon, 'HPP penjualan moving average'));
        $this->assertSame(0.0, $recon['difference']);
        $this->capturePage('partial', $response);
    }

    public function test_corrupted_closing_or_invoice_cost_is_reported_not_silently_balanced(): void
    {
        $this->receive(10, 10000);
        $sale = $this->sell(3);
        $sale->items()->update(['cost_subtotal' => 30001]);
        $response = $this->report();
        $recon = $response->viewData('reconciliation');
        $this->assertSame('mismatch', $recon['status']);
        $this->assertSame(-1.0, $recon['difference']);
        $this->capturePage('mismatch', $response);
        $sale->items()->update(['cost_subtotal' => 30000]);
        DB::table('inventory_cost_entries')->where('kind', 'sale')->update(['value_after' => 70001]);
        $recon = $this->report()->viewData('reconciliation');
        $this->assertSame('mismatch', $recon['status']);
        $this->assertSame(-1.0, $recon['difference']);
    }

    public function test_missing_hpp_does_not_produce_a_false_profit_or_healthy_reconciliation(): void
    {
        $this->receive(10, 10000);
        $sale = $this->sell(3);
        $sale->items()->update(['cost_subtotal' => -1]);
        $response = $this->report()->assertViewHas('grossProfit', null)->assertViewHas('operatingProfit', null);
        $recon = $response->viewData('reconciliation');
        $this->assertSame('mismatch', $recon['status']);
        $this->assertNull($recon['calculatedClosing']);
        $this->assertNull($recon['difference']);
    }

    public function test_empty_period_has_true_zero_balances_and_quarterly_yearly_boundaries_match(): void
    {
        $this->assertSame('matched', $this->report()->viewData('reconciliation')['status']);
        $this->receive(10, 10000, '2026-09-30');
        $this->sell(3, '2026-10-01');
        $this->receive(10, 12000, '2026-11-01');
        $quarter = $this->report(['period_type' => 'quarterly', 'year' => 2026, 'quarter' => 4])->viewData('reconciliation');
        $this->assertSame(100000.0, $quarter['opening']);
        $this->assertSame(190000.0, $quarter['closing']);
        $year = $this->report(['period_type' => 'yearly', 'year' => 2026])->viewData('reconciliation');
        $this->assertSame(0.0, $year['opening']);
        $this->assertSame(190000.0, $year['closing']);
        $this->assertSame(220000.0, $this->line($year, 'Penerimaan pembelian'));
    }

    public function test_previously_activated_opening_is_a_separate_reconciliation_component(): void
    {
        DB::table('inventory_cost_settings')->update(['automatic_from_zero' => false]);
        Stock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 20]);
        app(MovingAverageCostService::class)->opening($this->product, '1000000', '20', 'Saldo terverifikasi lama');
        app(MovingAverageCostService::class)->activate();
        $this->sell(3);
        $recon = $this->report()->viewData('reconciliation');
        $this->assertSame(1000000.0, $this->line($recon, 'Saldo awal penerapan metode'));
        $this->assertSame(0.0, $this->line($recon, 'Penerimaan pembelian'));
        $this->assertSame(850000.0, $recon['closing']);
        $this->assertSame('matched', $recon['status']);
    }

    public function test_unactivated_opening_draft_and_its_zero_reset_are_not_counted_as_receipts(): void
    {
        DB::table('inventory_cost_settings')->update(['automatic_from_zero' => false]);
        Stock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 5]);
        app(MovingAverageCostService::class)->opening($this->product, '60000', '5', 'Draft lama');
        DB::table('inventory_cost_settings')->update(['automatic_from_zero' => true]);
        $this->sell(5);
        $this->receive(10, 10000, '2026-10-03');
        $recon = $this->report()->viewData('reconciliation');
        $this->assertSame(0.0, $recon['opening']);
        $this->assertSame(100000.0, $recon['closing']);
        $this->assertSame(100000.0, $this->line($recon, 'Penerimaan pembelian'));
        $this->assertSame(0.0, $recon['difference']);
        $this->assertSame('partial', $recon['status']);
        $this->assertDatabaseHas('inventory_cost_entries', ['kind' => 'opening', 'value_change' => 60000]);
    }

    public function test_excel_preserves_unavailable_legacy_values_as_text_not_zero(): void
    {
        Stock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 10]);
        $response = $this->get(route('report.profit-loss.excel', ['month' => '2026-10']))->assertOk();
        $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $sheet = $book->getSheetByName('Rekonsiliasi Persediaan');
        $this->assertSame('Belum tersedia', $sheet->getCell('B5')->getValue());
        $this->assertStringContainsString('Belum Tersedia', $sheet->getCell('A3')->getValue());
        $this->assertSame('Belum tersedia', $sheet->getCell('B'.$sheet->getHighestDataRow())->getValue());
        $book->disconnectWorksheets();
    }

    public function test_financial_presentation_rounds_to_nearest_rupiah_without_changing_values(): void
    {
        $lines = [
            ['Pembulatan turun', 1250.49, false],
            ['Pembulatan naik', 1250.50, false],
            ['Beban turun', -1250.49, false],
            ['Beban naik', -1250.50, false],
            ['Belum lengkap', null, false],
        ];
        // This partial is shared by the screen and PDF.
        $html = view('report._financial-lines', compact('lines'))->render();
        foreach (['1.250', '1.251', '(1.250)', '(1.251)', 'Belum lengkap'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString(',49', $html);
        $this->assertStringNotContainsString(',50', $html);

        $export = new \App\Exports\ProfitLossExport('Uji pembulatan', $lines, []);
        $temporary = tmpfile();
        fwrite($temporary, \Maatwebsite\Excel\Facades\Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX));
        $book = IOFactory::load(stream_get_meta_data($temporary)['uri']);
        fclose($temporary);
        $sheet = $book->getSheet(0);
        foreach ([1250.49, 1250.50, -1250.49, -1250.50] as $index => $amount) {
            $cell = $sheet->getCell('B'.($index + 5));
            $this->assertEquals($amount, $cell->getValue());
            $expected = $amount < 0
                ? '('.number_format(abs($amount), 0, '.', ',').')'
                : number_format($amount, 0, '.', ',');
            $this->assertSame($expected, $cell->getFormattedValue());
        }
        $book->disconnectWorksheets();
    }

    public function test_pdf_and_two_excel_sheets_match_the_screen_and_preserve_business_data(): void
    {
        $this->receive(20, 50000);
        $this->sell(3);
        $this->receive(20, 55000, '2026-10-03');
        foreach (Expense::CATEGORIES as $category => $label) $this->expense($category, '1000.25');
        $this->expense('salary', '9999', 'draft');
        $this->expense('salary', '8888', 'void');
        $before = DB::table('inventory_cost_entries')->orderBy('id')->get()->toJson();
        $response = $this->report();
        $pdf = $this->get(route('report.profit-loss.pdf', ['month' => '2026-10']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $response->assertSee('Beban Operasional Lainnya')->assertDontSee('Beban Beban');
        $excel = $this->get(route('report.profit-loss.excel', ['month' => '2026-10']))->assertOk();
        $book = IOFactory::load($excel->baseResponse->getFile()->getPathname());
        $this->assertSame(['Laba Rugi', 'Rekonsiliasi Persediaan'], $book->getSheetNames());
        $main = $book->getSheet(0);
        $this->assertEquals($response->viewData('operatingProfit'), $main->getCell('B'.($main->getHighestDataRow()-2))->getValue());
        $this->assertSame('Belum lengkap', $main->getCell('B'.$main->getHighestDataRow())->getValue());
        $reconRows = $book->getSheet(1)->toArray(null, false, false, false);
        $rows = collect($reconRows)->mapWithKeys(fn ($row) => [$row[0] ?? '' => $row[1] ?? null]);
        $this->assertEquals(1950000, $rows['Persediaan akhir tercatat']);
        $this->assertEquals(2100000, $rows['Penerimaan pembelian']);
        $this->assertEquals(-150000, $rows['HPP penjualan moving average']);
        $this->assertEquals(0, $rows['Selisih rekonsiliasi']);
        $this->assertSame('0', $book->getSheet(1)->getCell('B5')->getFormattedValue());
        $this->assertSame('0', $book->getSheet(1)->getCell('B'.$book->getSheet(1)->getHighestDataRow())->getFormattedValue());
        $this->assertSame('', $main->getAutoFilter()->getRange());
        $this->assertSame($before, DB::table('inventory_cost_entries')->orderBy('id')->get()->toJson());
        if (getenv('CAPTURE_PL_REPORTS') === '1') {
            $directory = storage_path('framework/testing/profit-loss');
            if (!is_dir($directory)) mkdir($directory, 0777, true);
            file_put_contents($directory.'/complete.html', $response->getContent());
            file_put_contents($directory.'/complete.pdf', $pdf->getContent());
            copy($excel->baseResponse->getFile()->getPathname(), $directory.'/complete.xlsx');
        }
        $book->disconnectWorksheets();
    }
}
