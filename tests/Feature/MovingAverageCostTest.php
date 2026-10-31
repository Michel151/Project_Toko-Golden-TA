<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryCostBalance, Location, Product, ProductUnit, PurchaseOrder, PurchaseOrderItem, PurchaseReceipt, PurchaseReceiptItem, Sale, Stock, StockBatch, Supplier, Unit, User};
use App\Services\{MovingAverageCostService, StockService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MovingAverageCostTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private ProductUnit $unit;
    private Location $location;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('inventory_cost_settings')->where('id', 1)->update(['automatic_from_zero' => false]);
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 10, 21)->setTime(10, 0));
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@test.com', 'password' => 'password', 'role' => 'owner']);
        $this->actingAs($this->owner);
        $category = Category::create(['name' => 'Minuman']);
        $unit = Unit::create(['name' => 'Botol']);
        $this->location = Location::create(['name' => 'Toko']);
        $this->product = Product::create(['name' => 'Minuman', 'category_id' => $category->id, 'buy_unit_id' => $unit->id,
            'buy_price' => 999999, 'sell_price_wholesale' => 20000, 'can_retail' => false,
            'conversion_factor' => 1, 'min_stock_total' => 0, 'is_expirable' => false, 'is_active' => true]);
        $this->unit = ProductUnit::create(['product_id' => $this->product->id, 'unit_id' => $unit->id,
            'conversion_to_base' => 1, 'buy_price' => 12000, 'sell_price' => 20000, 'is_base_unit' => true,
            'is_default_purchase' => true, 'is_default_sale' => true]);
    }

    private function activate(float $quantity = 100, string $value = '1000000'): void
    {
        if ($quantity > 0) Stock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => $quantity]);
        app(MovingAverageCostService::class)->opening($this->product, $value, (string) $quantity, 'Diverifikasi dari catatan perolehan');
        app(MovingAverageCostService::class)->activate();
    }

    private function sale(float $quantity, string $status = 'paid'): Sale
    {
        $this->post(route('sale.sales.store'), ['sale_status' => $status, 'sale_date' => '2026-10-21',
            'transaction_confirmed' => 1, 'payment_method' => 'tunai', 'amount_paid' => $status === 'paid' ? 10000000 : 0,
            'items' => [['product_id' => $this->product->id, 'product_unit_id' => $this->unit->id,
                'quantity' => $quantity, 'unit_price' => '20000', 'sources' => [['location_id' => $this->location->id, 'quantity' => $quantity]]]],
        ])->assertSessionHasNoErrors()->assertSessionMissing('error')->assertRedirect(route('sale.sales.index'));
        return Sale::latest('id')->firstOrFail();
    }

    public function test_combined_pending_edit_and_payment_finalizes_cost_exactly_once(): void
    {
        $this->activate();
        $sale = $this->sale(5, 'pending');
        $payload = ['sale_status'=>'paid','sale_date'=>'2026-10-21','transaction_confirmed'=>1,
            'payment_method'=>'tunai','amount_paid'=>250000,'items'=>[[
                'product_id'=>$this->product->id,'product_unit_id'=>$this->unit->id,'quantity'=>10,'unit_price'=>20000,
                'sources'=>[['location_id'=>$this->location->id,'quantity'=>10]],
            ]]];
        $this->put(route('sale.sales.update',$sale),$payload)->assertSessionHasNoErrors()->assertSessionMissing('error');
        $this->assertSame('paid',$sale->fresh()->status);
        $this->assertEquals(50000,$sale->fresh()->change_amount);
        $this->assertEquals(100000,$sale->items()->sum('cost_subtotal'));
        $this->assertEquals(90,Stock::first()->quantity);
        $this->balance(90,900000,10000);
        $entries = DB::table('inventory_cost_entries')->count();
        $this->put(route('sale.sales.update',$sale),$payload)->assertRedirect(route('sale.sales.index'));
        $this->assertSame($entries,DB::table('inventory_cost_entries')->count());
        $this->balance(90,900000,10000);
    }

    private function receipt(float $quantity = 100, int $price = 12000, ?PurchaseOrderItem $source = null, string $date = '2026-10-21'): PurchaseReceiptItem
    {
        $supplier = Supplier::firstOrCreate(['name' => 'Supplier']);
        $po = PurchaseOrder::create(['po_number' => 'PO/test/'.(PurchaseOrder::count() + 1), 'supplier_id' => $supplier->id,
            'order_date' => $date, 'created_by' => $this->owner->id, 'status' => 'pending']);
        $item = PurchaseOrderItem::create(['purchase_order_id' => $po->id, 'product_id' => $this->product->id,
            'product_unit_id' => $this->unit->id, 'quantity_ordered' => $quantity, 'buy_price' => $price,
            'source_purchase_order_item_id' => $source?->id]);
        $receipt = PurchaseReceipt::create(['purchase_order_id' => $po->id, 'receipt_date' => $date, 'received_by' => $this->owner->id]);
        $line = PurchaseReceiptItem::create(['purchase_receipt_id' => $receipt->id, 'purchase_order_item_id' => $item->id,
            'product_id' => $this->product->id, 'product_unit_id' => $this->unit->id, 'location_id' => $this->location->id, 'quantity_received' => $quantity]);
        app(StockService::class)->receiveStockUsingUnit($this->product, $this->unit, $this->location, $quantity, $receipt, $date, $po->po_number, null, $line);
        return $line->fresh();
    }

    private function balance(float $quantity, float $value, float $average): void
    {
        $state = InventoryCostBalance::findOrFail($this->product->id);
        $this->assertEquals($quantity, (float) $state->quantity);
        $this->assertEquals($value, (float) $state->inventory_value);
        $this->assertEqualsWithDelta($average, (float) $state->average_cost, .000000001);
    }

    public function test_sales_use_moving_average_and_keep_historical_cost_after_receipt(): void
    {
        $this->activate();
        $first = $this->sale(40);
        $this->assertEquals(400000, $first->items->first()->cost_subtotal);
        $receipt = $this->receipt();
        $this->assertEquals(1200000, $receipt->acquisition_value);
        $this->assertEquals(100, $receipt->cost_base_quantity);
        $this->balance(160, 1800000, 11250);
        $second = $this->sale(50);
        $this->assertEquals(562500, $second->items->first()->cost_subtotal);
        $this->assertEquals('moving_average', $second->items->first()->cost_method);
        $this->balance(110, 1237500, 11250);
        $this->unit->update(['buy_price' => 999999]);
        $this->assertEquals(400000, $first->fresh()->items->first()->cost_subtotal);
        $this->assertEquals(1237500, $this->product->fresh()->inventoryValue());
    }

    public function test_pending_stock_remains_owned_until_payment_and_uses_payment_average(): void
    {
        $this->activate();
        $sale = $this->sale(40, 'pending');
        $this->balance(100, 1000000, 10000);
        $this->assertEquals(60, Stock::first()->quantity);
        $this->assertEquals(100, app(MovingAverageCostService::class)->ownedQuantity($this->product->id));
        $this->assertEquals(0, $sale->items->first()->cost_subtotal);
        $this->receipt();
        $this->balance(200, 2200000, 11000);
        $this->assertEquals(2200000, $this->product->fresh()->inventoryValue($this->location->id));
        $this->post(route('sale.sales.complete', $sale), ['payment_method' => 'tunai', 'amount_paid' => 10000000, 'payment_confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertEquals(440000, $sale->fresh()->items->first()->cost_subtotal);
        $this->balance(160, 1760000, 11000);
        $count = DB::table('inventory_cost_entries')->count();
        $this->post(route('sale.sales.complete', $sale), ['payment_method' => 'tunai', 'amount_paid' => 10000000, 'payment_confirmed' => 1]);
        $this->assertSame($count, DB::table('inventory_cost_entries')->count());
    }

    public function test_cancelling_pending_sale_only_releases_reservation(): void
    {
        $this->activate();
        $sale = $this->sale(40, 'pending');
        $this->post(route('sale.sales.cancel', $sale))->assertSessionHasNoErrors();
        $this->balance(100, 1000000, 10000);
        $this->assertEquals(100, Stock::first()->quantity);
        $this->assertSame(1, DB::table('inventory_cost_entries')->count());
    }

    public function test_fefo_and_transfers_do_not_change_global_cost(): void
    {
        $this->product->update(['is_expirable' => true]);
        $this->activate();
        $late = StockBatch::create(['product_id' => $this->product->id, 'location_id' => $this->location->id,
            'received_date' => '2026-10-20', 'expired_date' => '2027-01-01', 'quantity_initial' => 60, 'quantity_remaining' => 60]);
        $early = StockBatch::create(['product_id' => $this->product->id, 'location_id' => $this->location->id,
            'received_date' => '2026-10-21', 'expired_date' => '2026-11-01', 'quantity_initial' => 40, 'quantity_remaining' => 40]);
        $this->sale(30);
        $this->assertEquals(10, $early->fresh()->quantity_remaining);
        $this->assertEquals(60, $late->fresh()->quantity_remaining);
        $warehouse = Location::create(['name' => 'Gudang']);
        app(StockService::class)->transferStock($this->product, $this->location, $warehouse, 20, movementDate: '2026-10-21');
        $this->balance(70, 700000, 10000);
        $this->assertEquals(20, Stock::where('location_id', $warehouse->id)->first()->quantity);
        $this->assertSame(2, DB::table('inventory_cost_entries')->count());
    }

    public function test_replacement_receipt_uses_original_po_cost_not_zero_or_master(): void
    {
        $this->activate();
        $original = $this->receipt(10, 15000);
        $replacement = $this->receipt(10, 0, $original->purchaseOrderItem);
        $this->assertEquals(150000, $replacement->acquisition_value);
        $this->balance(120, 1300000, 10833.3333333333);
    }

    public function test_duplicate_receipt_rolls_back_physical_stock_and_value(): void
    {
        $this->activate();
        $line = $this->receipt();
        $movements = DB::table('stock_movements')->count();
        try {
            app(StockService::class)->receiveStockUsingUnit($this->product, $this->unit, $this->location, 100, $line->purchaseReceipt, '2026-10-21', receiptItem: $line);
            $this->fail('Duplicate receipt accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('sudah diproses', $e->getMessage());
        }
        $this->balance(200, 2200000, 11000);
        $this->assertEquals(200, Stock::first()->quantity);
        $this->assertSame($movements, DB::table('stock_movements')->count());
    }

    public function test_backdated_receipt_is_rejected_atomically(): void
    {
        $this->activate();
        try {
            $this->receipt(date: '2026-10-20');
            $this->fail('Backdated receipt accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('mendahului', $e->getMessage());
        }
        $this->balance(100, 1000000, 10000);
        $this->assertEquals(100, Stock::first()->quantity);
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_stock_adjustments_have_separate_values_and_owner_approval(): void
    {
        $this->activate();
        app(StockService::class)->adjustStock($this->product, $this->location, 90, movementDate: '2026-10-21');
        $this->balance(90, 900000, 10000);
        $this->assertDatabaseHas('inventory_cost_entries', ['kind' => 'stock_loss', 'value_change' => -100000]);
        try {
            app(StockService::class)->adjustStock($this->product, $this->location, 95, movementDate: '2026-10-21');
            $this->fail('Gain accepted without cost');
        } catch (ValidationException $e) {
            $this->assertEquals(90, Stock::first()->quantity);
        }
        app(StockService::class)->adjustStock($this->product, $this->location, 95, movementDate: '2026-10-21', valuationUnitCost: '12000');
        $this->balance(95, 960000, 10105.2631578947);
        $this->assertDatabaseHas('inventory_cost_entries', ['kind' => 'stock_gain', 'value_change' => 60000]);
    }

    public function test_rounding_exhausts_exact_inventory_value(): void
    {
        $this->activate(3, '1');
        $costs = [];
        foreach ([1, 1, 1] as $quantity) $costs[] = (float) $this->sale($quantity)->items->first()->cost_subtotal;
        $this->assertSame([.33, .34, .33], $costs);
        $this->balance(0, 0, 0);
    }

    public function test_activation_requires_current_verified_openings_and_preserves_old_sales(): void
    {
        Stock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 100]);
        $legacy = $this->sale(1);
        $oldCost = $legacy->items->first()->cost_subtotal;
        try {
            app(MovingAverageCostService::class)->activate();
            $this->fail('Activated unverified balance');
        } catch (ValidationException $e) {
            $this->assertFalse(app(MovingAverageCostService::class)->enabled());
        }
        app(MovingAverageCostService::class)->opening($this->product, '990000', '99', 'Verified');
        app(MovingAverageCostService::class)->opening($this->product, '999900', '99', 'Correction before activation');
        $this->assertEquals(999900, DB::table('inventory_cost_entries')->sum('value_change'));
        app(MovingAverageCostService::class)->activate();
        $this->assertEquals($oldCost, $legacy->fresh()->items->first()->cost_subtotal);
        $this->assertSame('legacy_master', $legacy->fresh()->items->first()->cost_method);
        $this->balance(99, 999900, 10100);
    }

    public function test_new_zero_stock_product_can_receive_after_activation(): void
    {
        app(MovingAverageCostService::class)->activate();
        $this->receipt(10, 12000);
        $this->balance(10, 120000, 12000);
    }

    public function test_receipt_quantities_are_converted_but_actual_invoice_value_is_not_multiplied_twice(): void
    {
        $this->activate();
        $carton = Unit::create(['name' => 'Dus']);
        $this->unit = ProductUnit::create(['product_id' => $this->product->id, 'unit_id' => $carton->id,
            'conversion_to_base' => 12, 'buy_price' => 999999, 'sell_price' => 240000]);
        $receipt = $this->receipt(2, 144000);
        $this->assertEquals(24, $receipt->cost_base_quantity);
        $this->assertEquals(288000, $receipt->acquisition_value);
        $this->balance(124, 1288000, 10387.0967741935);
    }

    public function test_expired_stock_keeps_cost_until_recorded_loss_and_is_not_sold(): void
    {
        $this->product->update(['is_expirable' => true]);
        $this->activate(10, '100000');
        $expired = StockBatch::create(['product_id' => $this->product->id, 'location_id' => $this->location->id,
            'received_date' => '2026-10-01', 'expired_date' => '2026-10-20', 'quantity_initial' => 3, 'quantity_remaining' => 3]);
        $valid = StockBatch::create(['product_id' => $this->product->id, 'location_id' => $this->location->id,
            'received_date' => '2026-10-01', 'expired_date' => '2027-01-01', 'quantity_initial' => 7, 'quantity_remaining' => 7]);
        $this->sale(7);
        $this->assertEquals(3, $expired->fresh()->quantity_remaining);
        $this->assertEquals(0, $valid->fresh()->quantity_remaining);
        $this->balance(3, 30000, 10000);
        app(StockService::class)->adjustStock($this->product, $this->location, 0, movementDate: '2026-10-21');
        $this->balance(0, 0, 0);
        $this->get(route('report.profit-loss'))->assertOk()->assertViewHas('operatingProfit', 40000)
            ->assertSee('Pendapatan dan Beban Lainnya Neto');
    }

    public function test_non_owner_cannot_add_opname_value_and_failure_rolls_back(): void
    {
        $this->activate();
        $warehouseUser = User::create(['name' => 'Gudang', 'email' => 'warehouse@test.com', 'password' => 'password', 'role' => 'staf_gudang']);
        $this->actingAs($warehouseUser);
        try {
            app(StockService::class)->adjustStock($this->product, $this->location, 110, movementDate: '2026-10-21', valuationUnitCost: '12000');
            $this->fail('Non-owner gain accepted');
        } catch (ValidationException $e) {
            $this->balance(100, 1000000, 10000);
            $this->assertEquals(100, Stock::first()->quantity);
        }
    }

    public function test_stock_change_after_opening_requires_reverification_before_activation(): void
    {
        Stock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 100]);
        app(MovingAverageCostService::class)->opening($this->product, '1000000', '100', 'Verified');
        $this->receipt();
        try {
            app(MovingAverageCostService::class)->activate();
            $this->fail('Stale opening activated');
        } catch (ValidationException $e) {
            $this->assertFalse(app(MovingAverageCostService::class)->enabled());
        }
    }

    public function test_initial_import_creates_cost_and_not_opname_income(): void
    {
        app(MovingAverageCostService::class)->activate();
        app(StockService::class)->initializeImportedStock($this->product, $this->location, 10, $this->product);
        $this->balance(10, 120000, 12000);
        $this->assertDatabaseHas('inventory_cost_entries', ['kind' => 'receipt', 'value_change' => 120000]);
        $this->get(route('report.profit-loss'))->assertOk()->assertViewHas('operatingProfit', 0);
    }

    public function test_costing_ui_is_read_only_without_manual_opening_or_activation(): void
    {
        DB::table('inventory_cost_settings')->where('id', 1)->update(['automatic_from_zero' => true]);
        $this->capture('costing-ready', route('inventory.costing.index'));
        Stock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 100]);
        $this->capture('costing-legacy', route('inventory.costing.index'));
        $this->sale(100);
        $this->receipt(100, 10000);
        $this->sale(10);
        $this->capture('costing-active', route('inventory.costing.index'));
        $this->capture('costing-history', route('inventory.costing.show', $this->product));
        $this->capture('costing-profit-loss', route('report.profit-loss'));
        $this->get(route('report.stock'))->assertOk();
        $this->get(route('inventory.costing.index'))->assertOk()->assertDontSee('name="inventory_value"', false)
            ->assertDontSee('Aktifkan Moving Average')->assertSee('Otomatis dari Stok Nol');
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('inventory.costing.opening'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('inventory.costing.activate'));
        $this->post('/inventory/costing/'.$this->product->id.'/opening', ['inventory_value' => 0])->assertNotFound();
        $this->balance(90, 900000, 10000);
        $this->assertEquals(90, Stock::first()->quantity);
    }

    public function test_full_receipt_return_replacement_flow_only_capitalizes_accepted_goods_once(): void
    {
        $this->activate();
        $supplier = Supplier::create(['name' => 'Supplier retur']);
        $po = PurchaseOrder::create(['po_number' => 'PO/2026/10/000001', 'supplier_id' => $supplier->id,
            'order_date' => '2026-10-21', 'status' => 'pending', 'created_by' => $this->owner->id]);
        $poItem = $po->items()->create(['product_id' => $this->product->id, 'product_unit_id' => $this->unit->id,
            'quantity_ordered' => 20, 'buy_price' => 15000]);
        $payload = ['submission_token' => (string) str()->uuid(), 'purchase_order_id' => $po->id, 'receipt_date' => '2026-10-21',
            'items' => [['purchase_order_item_id' => $poItem->id, 'product_id' => $this->product->id, 'product_unit_id' => $this->unit->id,
                'quantity_received' => 10, 'quantity_missing' => 10, 'reason' => 'Kurang kirim',
                'allocations' => [['location_id' => $this->location->id, 'quantity' => 10]]]]];
        $service = app(\App\Services\PurchaseFulfillmentService::class);
        $receipt = $service->receive($payload, $this->owner->id);
        $this->balance(110, 1150000, 10454.5454545455);
        $this->assertSame($receipt->id, $service->receive($payload, $this->owner->id)->id);
        $this->assertEquals(110, Stock::first()->quantity);
        $claim = $service->createReturn(['purchase_receipt_id' => $receipt->id,
            'items' => [['purchase_inspection_id' => $receipt->inspections()->first()->id, 'kind' => 'missing', 'quantity' => 10]]], $this->owner->id);
        $claim = $service->approve($claim, $this->owner->id);
        $replacement = $claim->replacementOrder;
        $replacementItem = $replacement->items()->first();
        $payload['submission_token'] = (string) str()->uuid();
        $payload['purchase_order_id'] = $replacement->id;
        $payload['items'][0]['purchase_order_item_id'] = $replacementItem->id;
        $payload['items'][0]['quantity_missing'] = 0;
        $service->receive($payload, $this->owner->id);
        $this->balance(120, 1300000, 10833.3333333333);
        $this->assertEquals(300000, PurchaseReceiptItem::sum('acquisition_value'));
        $this->assertEquals(120, Stock::first()->quantity);
    }

    public function test_opname_completion_records_owner_cost_and_cannot_be_applied_twice(): void
    {
        $this->activate();
        $this->post(route('inventory.opnames.store'), ['location_id' => $this->location->id, 'opname_date' => '2026-10-21'])->assertSessionHasNoErrors();
        $opname = \App\Models\StockOpname::firstOrFail();
        $item = $opname->items()->firstOrFail();
        $this->capture('costing-opname', route('inventory.opnames.edit', $opname));
        $payload = ['items' => [$item->id => ['physical_quantity' => 105, 'valuation_unit_cost' => '12000', 'notes' => 'Ditemukan stok tambahan saat pemeriksaan.']]];
        $this->post(route('inventory.opnames.complete', $opname), $payload)->assertSessionHasNoErrors();
        $this->balance(105, 1060000, 10095.2380952381);
        $this->assertSame('completed', $opname->fresh()->status);
        $this->sale(5);
        $state = InventoryCostBalance::first()->toArray();
        $this->post(route('inventory.opnames.complete', $opname), $payload);
        $this->assertEquals($state, InventoryCostBalance::first()->toArray());
        $this->assertEquals(100, Stock::first()->quantity);
    }

    public function test_stock_and_profit_exports_use_recorded_cost_including_pending_reservations(): void
    {
        $this->activate();
        $this->sale(40, 'pending');
        $this->get(route('report.stock'))->assertOk()->assertViewHas('stockSummary', fn ($summary) => $summary['value'] === 1000000.0);
        $this->get(route('report.stock.pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        \Maatwebsite\Excel\Facades\Excel::fake();
        $this->get(route('report.stock.excel'))->assertOk();
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('Laporan-Stok-2026-10-21.xlsx', function (\App\Exports\ReportExport $export) {
            $rows = $export->array();
            $row = collect($rows)->first(fn ($row) => ($row[0] ?? null) === $this->product->name);
            $this->assertEquals(1000000, $row[count($row) - 2]);
            return true;
        });
        $this->get(route('report.profit-loss.pdf'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('report.profit-loss.excel'))->assertOk();
    }

    public function test_future_dated_cost_does_not_block_subsequent_current_receipts(): void
    {
        $this->activate();
        try {
            $this->receipt(date: '2026-10-22');
            $this->fail('Future cost accepted');
        } catch (ValidationException $e) {
            $this->balance(100, 1000000, 10000);
        }
        $this->receipt();
        $this->balance(200, 2200000, 11000);
    }

    private function capture(string $name, string $url): void
    {
        $response = $this->get($url)->assertOk();
        if (getenv('CAPTURE_COST_UI') === '1') {
            $directory = storage_path('framework/testing/costing');
            if (!is_dir($directory)) mkdir($directory, 0777, true);
            file_put_contents($directory.'/'.$name.'.html', $response->getContent());
        }
    }

    private function automatic(): void
    {
        DB::table('inventory_cost_settings')->where('id', 1)->update(['automatic_from_zero' => true]);
        $this->assertTrue(app(MovingAverageCostService::class)->enabled());
    }

    public function test_cost_history_references_show_po_and_invoice_numbers_without_rewriting_entries(): void
    {
        $this->automatic();
        $first = $this->receipt(10, 10000);
        $firstPo = $first->purchaseReceipt->purchaseOrder;
        $firstPo->update(['po_number' => 'PO/2026/10/000001']);
        $sale = $this->sale(3);
        PurchaseOrder::create(['po_number' => 'PO/2026/10/000099', 'supplier_id' => $firstPo->supplier_id,
            'order_date' => '2026-10-21', 'created_by' => $this->owner->id, 'status' => 'pending']);
        $second = $this->receipt(10, 12000);
        $secondPo = $second->purchaseReceipt->purchaseOrder;
        $secondPo->update(['po_number' => 'PO/R/2026/10/000001']);
        $before = DB::table('inventory_cost_entries')->orderBy('id')->get()->toJson();
        $this->get(route('inventory.costing.show', $this->product))->assertOk()
            ->assertSee('href="'.route('purchase.orders.show', $firstPo).'">'.$firstPo->po_number.'</a>', false)
            ->assertSee('href="'.route('purchase.orders.show', $secondPo).'">'.$secondPo->po_number.'</a>', false)
            ->assertSee('href="'.route('sale.sales.show', $sale).'">'.$sale->sale_number.'</a>', false)
            ->assertDontSee('PurchaseReceipt #')->assertDontSee('Sale #');
        $this->assertNotEquals($secondPo->id, $second->purchase_receipt_id);
        $this->get(route('purchase.orders.show', $firstPo))->assertOk()->assertSee($firstPo->po_number);
        $this->get(route('purchase.orders.show', $secondPo))->assertOk()->assertSee($secondPo->po_number);
        $this->get(route('sale.sales.show', $sale))->assertOk()->assertSee($sale->sale_number);
        $this->assertSame($before, DB::table('inventory_cost_entries')->orderBy('id')->get()->toJson());
    }

    public function test_missing_cost_history_document_displays_a_dash_instead_of_internal_model_name(): void
    {
        $this->automatic();
        $this->receipt(10, 10000);
        $this->sale(3);
        DB::table('inventory_cost_entries')->update(['reference_id' => 999999]);
        $response = $this->get(route('inventory.costing.show', $this->product))->assertOk()
            ->assertDontSee('PurchaseReceipt')->assertDontSee('Sale #');
        $this->assertSame(['-', '-'], $response->viewData('entries')->getCollection()->pluck('reference_label')->all());
        $this->assertSame([null, null], $response->viewData('entries')->getCollection()->pluck('reference_url')->all());
    }

    public function test_costing_numbers_are_readable_without_changing_stored_precision(): void
    {
        $this->automatic();
        $baseUnit = $this->unit;
        $carton = Unit::create(['name' => 'Dus']);
        $cartonUnit = ProductUnit::create(['product_id' => $this->product->id, 'unit_id' => $carton->id,
            'conversion_to_base' => 10, 'buy_price' => 500000, 'sell_price' => 650000]);
        $this->unit = $cartonUnit;
        $this->receipt(2, 500000);
        $this->unit = $baseUnit;
        $this->sale(3);
        $this->unit = $cartonUnit;
        $this->receipt(2, 550000);
        $before = InventoryCostBalance::first()->toArray();
        $this->get(route('inventory.costing.show', $this->product))->assertOk()
            ->assertSee('>20</td>', false)->assertSee('>-3</td>', false)->assertSee('>37</td>', false)
            ->assertSee('1.950.000,00')->assertSee('52.702,70')->assertSee('Rata-rata (Rp)')
            ->assertDontSee('37,0000')->assertDontSee('52.702,702703')->assertDontSee('50.000,000000');
        $this->get(route('inventory.costing.index'))->assertOk()->assertSee('37 Botol')->assertSee('52.702,70')->assertDontSee('37,0000');
        $this->assertSame($before, InventoryCostBalance::first()->toArray());
        $this->assertEqualsWithDelta(52702.7027027027, (float) InventoryCostBalance::first()->average_cost, .000000001);
        $this->capture('costing-format-history', route('inventory.costing.show', $this->product));
        $this->capture('costing-format-list', route('inventory.costing.index'));
    }

    public function test_costing_quantity_display_preserves_significant_fractional_digits(): void
    {
        $this->automatic();
        $this->receipt(1.25, 10000);
        $this->sale(.25);
        $this->get(route('inventory.costing.show', $this->product))->assertOk()
            ->assertSee('>1,25</td>', false)->assertSee('>-0,25</td>', false)->assertSee('>1</td>', false)
            ->assertDontSee('1,2500')->assertDontSee('-0,2500');
        $this->balance(1, 10000, 10000);
    }

    public function test_automatic_first_receipts_from_zero_create_average_without_opening_or_activation(): void
    {
        $this->automatic();
        $this->assertNull(DB::table('inventory_cost_settings')->value('enabled_at'));
        $this->assertSame(0, InventoryCostBalance::count());
        $this->receipt(10, 100000);
        $this->balance(10, 1000000, 100000);
        $this->receipt(10, 120000);
        $this->balance(20, 2200000, 110000);
        $sale = $this->sale(3);
        $this->assertEquals(330000, $sale->items->first()->cost_subtotal);
        $this->assertSame('moving_average', $sale->items->first()->cost_method);
        $this->balance(17, 1870000, 110000);
        $this->assertDatabaseMissing('inventory_cost_entries', ['kind' => 'opening']);
    }

    public function test_automatic_costing_keeps_fefo_separate_from_average_cost(): void
    {
        $this->automatic();
        $this->product->update(['is_expirable' => true]);
        $first = $this->receipt(10, 10000);
        $first->stockBatch->update(['expired_date' => '2026-12-01']);
        $second = $this->receipt(10, 12000);
        $second->stockBatch->update(['expired_date' => '2026-11-01']);
        $sale = $this->sale(12);
        $this->assertEquals(0, $second->stockBatch->fresh()->quantity_remaining);
        $this->assertEquals(8, $first->stockBatch->fresh()->quantity_remaining);
        $this->assertEquals(132000, $sale->items->first()->cost_subtotal);
        $this->balance(8, 88000, 11000);
    }

    public function test_automatic_reservation_is_valued_at_payment_and_cancel_does_not_change_cost(): void
    {
        $this->automatic();
        $this->receipt(100, 10000);
        $pending = $this->sale(40, 'pending');
        $this->receipt(100, 12000);
        $this->balance(200, 2200000, 11000);
        $this->post(route('sale.sales.complete', $pending), ['payment_method' => 'tunai', 'amount_paid' => 10000000, 'payment_confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertEquals(440000, $pending->fresh()->items->first()->cost_subtotal);
        $this->balance(160, 1760000, 11000);
        $cancelled = $this->sale(10, 'pending');
        $entries = DB::table('inventory_cost_entries')->count();
        $this->post(route('sale.sales.cancel', $cancelled))->assertSessionHasNoErrors();
        $this->assertSame($entries, DB::table('inventory_cost_entries')->count());
        $this->balance(160, 1760000, 11000);
    }

    public function test_legacy_stock_and_new_receipts_remain_legacy_until_owned_stock_reaches_zero(): void
    {
        Stock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 5]);
        $this->automatic();
        $this->receipt(5, 15000);
        $this->assertSame(0, InventoryCostBalance::count());
        $this->assertSame(0, DB::table('inventory_cost_entries')->count());
        $sale = $this->sale(10);
        $this->assertSame('legacy_master', $sale->items->first()->cost_method);
        $this->assertEquals(120000, $sale->items->first()->cost_subtotal);
        $this->assertSame(0, InventoryCostBalance::count());
        $this->receipt(10, 15000);
        $this->balance(10, 150000, 15000);
        $newSale = $this->sale(1);
        $this->assertSame('moving_average', $newSale->items->first()->cost_method);
        $this->assertEquals(15000, $newSale->items->first()->cost_subtotal);
        $this->assertEquals(120000, $sale->fresh()->items->first()->cost_subtotal);
    }

    public function test_reserved_legacy_stock_prevents_false_zero_start_and_can_be_completed(): void
    {
        Stock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 5]);
        $oldPending = $this->sale(5, 'pending');
        $this->automatic();
        $this->assertEquals(0, Stock::first()->quantity);
        $this->assertFalse(app(MovingAverageCostService::class)->canTrack($this->product->fresh()));
        $this->receipt(5, 15000);
        $this->assertSame(0, InventoryCostBalance::count());
        $this->post(route('sale.sales.complete', $oldPending), ['payment_method' => 'tunai', 'amount_paid' => 10000000, 'payment_confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame('paid', $oldPending->fresh()->status);
        $this->assertEquals(60000, $oldPending->fresh()->items->first()->cost_subtotal);
        $this->sale(5);
        $this->receipt(10, 13000);
        $this->balance(10, 130000, 13000);
    }

    public function test_legacy_payment_does_not_require_reconstructing_unknown_historical_cost_sources(): void
    {
        Stock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 5]);
        $pending = $this->sale(5, 'pending');
        DB::table('sale_item_sources')->update(['base_quantity' => null]);
        DB::table('stock_movements')->delete();
        $this->automatic();
        $this->assertFalse(app(MovingAverageCostService::class)->canTrack($this->product->fresh()));
        $this->post(route('sale.sales.complete', $pending), ['payment_method' => 'tunai', 'amount_paid' => 10000000, 'payment_confirmed' => 1])->assertSessionHasNoErrors();
        $this->assertSame('paid', $pending->fresh()->status);
        $this->assertEquals(60000, $pending->fresh()->items->first()->cost_subtotal);
        $this->assertSame(0, InventoryCostBalance::count());
        $this->receipt(5, 10000);
        $this->balance(5, 50000, 10000);
    }

    public function test_legacy_opname_loss_to_zero_does_not_create_negative_cost_or_erase_history(): void
    {
        Stock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 5]);
        $this->automatic();
        app(StockService::class)->adjustStock($this->product, $this->location, 0, movementDate: '2026-10-21');
        $this->assertSame(0, InventoryCostBalance::count());
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'opname_adjustment')->count());
        $this->receipt(5, 10000);
        $this->balance(5, 50000, 10000);
    }

    public function test_previously_activated_balance_is_preserved_when_automatic_mode_is_enabled(): void
    {
        $this->activate();
        $this->automatic();
        $this->balance(100, 1000000, 10000);
        $this->receipt();
        $this->balance(200, 2200000, 11000);
        $this->assertSame(1, DB::table('inventory_cost_entries')->where('kind', 'opening')->count());
    }

    public function test_unused_manual_opening_is_not_applied_to_legacy_stock_or_deleted(): void
    {
        Stock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 5]);
        app(MovingAverageCostService::class)->opening($this->product, '60000', '5', 'Draft lama');
        $opening = DB::table('inventory_cost_entries')->where('kind', 'opening')->first();
        $this->automatic();
        $this->sale(5);
        $this->receipt(5, 10000);
        $this->balance(5, 50000, 10000);
        $this->assertEquals((array) $opening, (array) DB::table('inventory_cost_entries')->find($opening->id));
        $this->assertEquals(50000, DB::table('inventory_cost_entries')->sum('value_change'));
    }

    public function test_costing_pages_and_write_routes_are_owner_only(): void
    {
        $this->get(route('inventory.costing.index'))->assertOk()->assertSee('Penilaian Persediaan');
        $this->activate();
        $this->get(route('inventory.costing.show', $this->product))->assertOk();
        foreach (['kasir', 'staf_gudang'] as $role) {
            $user = User::create(['name' => $role, 'email' => $role.'@test.com', 'password' => 'password', 'role' => $role]);
            $this->actingAs($user);
            $this->get(route('inventory.costing.index'))->assertForbidden();
            $this->get(route('inventory.costing.show', $this->product))->assertForbidden();
        }
    }
}
