<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseInspection;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReturn;
use App\Models\Stock;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseReturnWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Location $location;

    private PurchaseOrder $order;

    private function capturePage(string $name, $response): void
    {
        if (getenv('CAPTURE_PURCHASE_UI') !== '1') {
            return;
        }
        $directory = storage_path('framework/testing/purchase-ui');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($directory.'/'.$name.'.html', $response->getContent());
    }

    private function setupOrder(): void
    {
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner-return@example.com', 'password' => 'password', 'role' => 'owner']);
        $this->actingAs($this->owner);
        $this->location = Location::create(['name' => 'Gudang']);
        $unit = Unit::create(['name' => 'Dus']);
        $category = Category::create(['name' => 'Minuman']);
        $product = Product::create(['name' => 'Produk Batch', 'category_id' => $category->id, 'buy_unit_id' => $unit->id,
            'buy_price' => 12000, 'sell_price_wholesale' => 15000, 'is_expirable' => true]);
        $productUnit = ProductUnit::create(['product_id' => $product->id, 'unit_id' => $unit->id, 'conversion_to_base' => 6,
            'buy_price' => 12000, 'sell_price' => 15000, 'is_default_purchase' => true]);
        $supplier = Supplier::create(['name' => 'Supplier Uji']);
        $this->order = PurchaseOrder::create(['po_number' => 'PO-2026-000001', 'supplier_id' => $supplier->id,
            'order_date' => today(), 'status' => 'pending', 'created_by' => $this->owner->id]);
        $this->order->items()->create(['product_id' => $product->id, 'product_unit_id' => $productUnit->id,
            'quantity_ordered' => 100, 'buy_price' => 12000]);
    }

    private function payload(PurchaseOrder $order, float $good, float $damaged = 0, float $wrong = 0, float $missing = 0): array
    {
        $item = $order->items()->first();

        return ['submission_token' => (string) Str::uuid(), 'purchase_order_id' => $order->id, 'receipt_date' => today()->toDateString(),
            'items' => [['purchase_order_item_id' => $item->id, 'product_id' => $item->product_id, 'product_unit_id' => $item->product_unit_id,
                'quantity_received' => $good, 'quantity_damaged' => $damaged, 'quantity_wrong' => $wrong, 'quantity_missing' => $missing,
                'reason' => 'Segel rusak / salah barang / kurang kirim', 'quarantine_location_id' => $this->location->id,
                'expired_date' => today()->addMonths(3)->toDateString(),
                'allocations' => [['location_id' => $this->location->id, 'quantity' => $good]]]]];
    }

    private function claim(PurchaseReceipt $receipt): PurchaseReturn
    {
        $rows = [];
        foreach ($receipt->inspections as $inspection) {
            foreach (['damaged', 'wrong', 'missing'] as $kind) {
                if ($inspection->claimable($kind) > 0) {
                    $rows[] = ['purchase_inspection_id' => $inspection->id, 'kind' => $kind, 'quantity' => $inspection->claimable($kind)];
                }
            }
        }
        $this->post(route('purchase.returns.store'), ['purchase_receipt_id' => $receipt->id, 'items' => $rows])
            ->assertSessionHasNoErrors()->assertRedirect();

        return PurchaseReturn::latest('id')->first();
    }

    private function batchPayload(PurchaseOrder $order, array $batches): array
    {
        $data = $this->payload($order, array_sum(array_column($batches, 'quantity')));
        unset($data['items'][0]['allocations'], $data['items'][0]['expired_date']);
        $data['items'][0]['batches'] = $batches;

        return $data;
    }

    public function test_stock_card_batch_details_show_each_origin_po_and_keep_imported_batches_without_po(): void
    {
        $this->setupOrder();
        $expiry = today()->addMonths(2)->toDateString();
        $this->post(route('purchase.receipts.store'), $this->batchPayload($this->order, [
            ['quantity' => 2, 'location_id' => $this->location->id, 'expired_date' => $expiry, 'batch_code' => 'PO-ORIGIN-A'],
        ]))->assertSessionHasNoErrors();
        $item = $this->order->items()->first();
        $other = PurchaseOrder::create(['po_number' => 'PO-2026-000002', 'supplier_id' => $this->order->supplier_id,
            'order_date' => today(), 'status' => 'pending', 'created_by' => $this->owner->id]);
        $other->items()->create(['product_id' => $item->product_id, 'product_unit_id' => $item->product_unit_id,
            'quantity_ordered' => 2, 'buy_price' => 12000]);
        $this->post(route('purchase.receipts.store'), $this->batchPayload($other, [
            ['quantity' => 2, 'location_id' => $this->location->id, 'expired_date' => $expiry, 'batch_code' => 'PO-ORIGIN-B'],
        ]))->assertSessionHasNoErrors();
        StockBatch::create(['product_id' => $item->product_id, 'location_id' => $this->location->id,
            'received_date' => today(), 'expired_date' => $expiry, 'quantity_initial' => 6, 'quantity_remaining' => 6, 'batch_code' => 'IMPORT-NO-PO']);
        Stock::where('product_id', $item->product_id)->increment('quantity', 6);
        $url = route('inventory.stock-card.show', ['product' => $item->product_id, 'tab' => 'expiry']);
        $response = $this->get($url)->assertOk()->assertSee('No. PO')
            ->assertSee($this->order->po_number)->assertSee($other->po_number)
            ->assertDontSee('ID Batch')->assertDontSee('PO-ORIGIN-A')->assertDontSee('PO-ORIGIN-B')->assertDontSee('IMPORT-NO-PO')
            ->assertSee('href="'.route('purchase.orders.show', $this->order).'"', false)
            ->assertSee('href="'.route('purchase.orders.show', $other).'"', false);
        $this->capturePage('stock-card-batch-po', $response);
        $this->assertMatchesRegularExpression('/<tr><td>\s*<span class="text-muted">-<\/span>\s*<\/td><td>/', $response->getContent());
        foreach (['PO-ORIGIN-A', 'PO-ORIGIN-B', 'IMPORT-NO-PO'] as $code) {
            $this->assertDatabaseHas('stock_batches', ['batch_code' => $code]);
        }
        $this->get(route('master.products.show', ['product' => $item->product_id, 'tab' => 'expiry']))
            ->assertOk()->assertDontSee('ID Batch')->assertDontSee('PO-ORIGIN-A')->assertDontSee('IMPORT-NO-PO');
        foreach (['staf_gudang', 'kasir'] as $role) {
            $user = User::create(['name' => $role, 'email' => $role.'-batch-po@example.com', 'password' => 'password', 'role' => $role]);
            $this->actingAs($user)->get($url)->assertOk()->assertSee($this->order->po_number)->assertSee($other->po_number)
                ->assertDontSee('href="'.route('purchase.orders.show', $this->order).'"', false)
                ->assertDontSee('href="'.route('purchase.orders.show', $other).'"', false);
        }
    }

    public function test_transferred_single_batch_keeps_the_receipt_po_reference(): void
    {
        $this->setupOrder();
        $this->post(route('purchase.receipts.store'), $this->batchPayload($this->order, [
            ['quantity' => 2, 'location_id' => $this->location->id, 'expired_date' => today()->addMonths(2)->toDateString(), 'batch_code' => 'TRANSFER-ORIGIN'],
        ]))->assertSessionHasNoErrors();
        $item = $this->order->items()->first();
        $destination = Location::create(['name' => 'Toko Tujuan']);
        app(\App\Services\StockService::class)->transferStock($item->product, $this->location, $destination, 6);
        $destinationBatch = StockBatch::where('location_id', $destination->id)->firstOrFail();
        $this->assertEquals($this->order->id, $destinationBatch->purchaseReceiptItem->purchaseReceipt->purchaseOrder->id);
        $response = $this->get(route('inventory.stock-card.show', ['product' => $item->product_id, 'tab' => 'expiry']))
            ->assertOk()->assertSee('No. PO')->assertSee('Toko Tujuan');
        $this->assertEquals(2, substr_count($response->getContent(), 'href="'.route('purchase.orders.show', $this->order).'"'));
    }

    public function test_one_receipt_accepts_multiple_expiry_batches_and_stock_deduction_remains_fefo(): void
    {
        $this->setupOrder();
        $this->capturePage('receipt-batches-form', $this->get(route('purchase.receipts.create', ['order_id' => $this->order->id]))
            ->assertOk()->assertSee('Rincian Kedaluwarsa')->assertSee('Tambah Kedaluwarsa')
            ->assertDontSee('Kode Batch Supplier')->assertDontSee('][batch_code]')->assertDontSee('Rincian Batch Barang Baik'));
        $data = $this->batchPayload($this->order, [
            ['quantity' => 12, 'location_id' => $this->location->id, 'expired_date' => today()->addDays(90)->toDateString(), 'batch_code' => 'LOT-LATER'],
            ['quantity' => 8, 'location_id' => $this->location->id, 'expired_date' => today()->addDays(30)->toDateString(), 'batch_code' => 'LOT-EARLY'],
        ]);
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertDatabaseCount('purchase_inspections', 1);
        $this->assertDatabaseCount('purchase_receipt_items', 2);
        $this->assertDatabaseCount('stock_batches', 2);
        $this->assertEquals(20, $this->order->items()->first()->quantity_received);
        $this->assertEquals(20, PurchaseInspection::first()->quantity_good);
        $this->assertEquals(120, Stock::sum('quantity'));
        $this->capturePage('receipt-batches-show', $this->get(route('purchase.receipts.show', PurchaseReceipt::first()))
            ->assertOk()->assertSee('Rincian Penerimaan')->assertDontSee('Kode Batch')->assertDontSee('LOT-LATER')->assertDontSee('LOT-EARLY'));
        $later = StockBatch::where('batch_code', 'LOT-LATER')->firstOrFail();
        $earlier = StockBatch::where('batch_code', 'LOT-EARLY')->firstOrFail();
        $item = $this->order->items()->first();
        app(StockService::class)->deductStock($item->product, $this->location, 9, (string) $item->product_unit_id);
        $this->assertEquals(0, $earlier->fresh()->quantity_remaining);
        $this->assertEquals(66, $later->fresh()->quantity_remaining);
        $this->assertEquals(66, Stock::sum('quantity'));
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertEquals(66, Stock::sum('quantity'));
    }

    public function test_same_supplier_batch_can_be_allocated_to_multiple_locations(): void
    {
        $this->setupOrder();
        $other = Location::create(['name' => 'Toko']);
        $expiry = today()->addMonths(2)->toDateString();
        $data = $this->batchPayload($this->order, [
            ['quantity' => 7.5, 'location_id' => $this->location->id, 'expired_date' => $expiry, 'batch_code' => '0'],
            ['quantity' => 12.5, 'location_id' => $other->id, 'expired_date' => $expiry, 'batch_code' => '0'],
        ]);
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stocks', ['location_id' => $this->location->id, 'quantity' => 45]);
        $this->assertDatabaseHas('stocks', ['location_id' => $other->id, 'quantity' => 75]);
        $this->assertEquals(2, StockBatch::where('batch_code', '0')->count());
        $this->assertEquals(20, $this->order->items()->first()->quantity_received);
    }

    public function test_batch_totals_expiry_location_and_code_consistency_are_validated_atomically(): void
    {
        $this->setupOrder();
        $valid = ['quantity' => 10, 'location_id' => $this->location->id, 'expired_date' => today()->addMonths(2)->toDateString(), 'batch_code' => 'LOT-01'];
        $data = $this->batchPayload($this->order, [$valid]);
        $data['items'][0]['quantity_received'] = 20;
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasErrors('items.0.batches');
        $data = $this->batchPayload($this->order, [$valid, [...$valid, 'expired_date' => null]]);
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasErrors('items.0.batches.1.expired_date');
        $data = $this->batchPayload($this->order, [[...$valid, 'location_id' => null]]);
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasErrors('items.0.batches.0.location_id');
        $data = $this->batchPayload($this->order, [$valid, [...$valid, 'expired_date' => today()->addMonths(3)->toDateString()]]);
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasErrors('items.0.batches.1.batch_code');
        $data = $this->batchPayload($this->order, [[...$valid, 'expired_date' => '2026-02-30']]);
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasErrors('items.0.batches.0.expired_date');
        $data = $this->batchPayload($this->order, [$valid]);
        $data['items'][0]['quantity_received'] = 0;
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasErrors('items.0.batches');
        $data = $this->batchPayload($this->order, [$valid]);
        $data['items'][0]['allocations'] = [['location_id' => $this->location->id, 'quantity' => 10]];
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasErrors('items.0.batches');
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('purchase_inspections', 0);
        $this->assertDatabaseCount('purchase_receipt_items', 0);
        $this->assertDatabaseCount('stocks', 0);
        $this->assertDatabaseCount('stock_batches', 0);
        $this->assertEquals(0, $this->order->items()->first()->quantity_received);
    }

    public function test_exception_only_receipt_allows_an_unused_batch_without_expiry(): void
    {
        $this->setupOrder();
        $data = $this->batchPayload($this->order, [['quantity' => 0, 'location_id' => null, 'expired_date' => null]]);
        $data['items'][0]['quantity_damaged'] = 5;
        $data['items'][0]['quantity_missing'] = 5;
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('stock_batches', 0);
        $this->assertDatabaseCount('stocks', 0);
        $this->assertEquals(10, $this->order->items()->first()->quantity_reserved);
    }

    public function test_batch_inputs_are_restored_after_failed_validation(): void
    {
        $this->setupOrder();
        $data = $this->batchPayload($this->order, [
            ['quantity' => 12, 'location_id' => $this->location->id, 'expired_date' => today()->addMonths(2)->toDateString(), 'batch_code' => 'RESTORE-A'],
            ['quantity' => 8, 'location_id' => $this->location->id, 'expired_date' => null, 'batch_code' => 'RESTORE-B'],
        ]);
        $url = route('purchase.receipts.create', ['order_id' => $this->order->id]);
        $this->from($url)->post(route('purchase.receipts.store'), $data)->assertRedirect($url)->assertSessionHasErrors('items.0.batches.1.expired_date');
        $this->get($url)->assertOk()->assertDontSee('RESTORE-A')->assertDontSee('RESTORE-B')
            ->assertSee('value="12"', false)->assertSee('value="8"', false)
            ->assertSee('name="items[0][batches][1][quantity]"', false);
        $this->assertDatabaseCount('purchase_receipts', 0);
    }

    public function test_new_expiry_details_need_no_supplier_code_and_leave_old_codes_unchanged(): void
    {
        $this->setupOrder();
        $this->post(route('purchase.receipts.store'), $this->batchPayload($this->order, [
            ['quantity' => 1, 'location_id' => $this->location->id, 'expired_date' => today()->addMonths(2)->toDateString(), 'batch_code' => 'HISTORICAL-CODE'],
        ]))->assertSessionHasNoErrors();
        $oldBatch = StockBatch::first();
        $this->post(route('purchase.receipts.store'), $this->batchPayload($this->order, [
            ['quantity' => 12, 'location_id' => $this->location->id, 'expired_date' => today()->addMonths(2)->toDateString()],
            ['quantity' => 8, 'location_id' => $this->location->id, 'expired_date' => today()->addMonths(3)->toDateString()],
        ]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('stock_batches', 3);
        $this->assertEquals('HISTORICAL-CODE', $oldBatch->fresh()->batch_code);
        $this->assertEquals(2, StockBatch::whereNull('batch_code')->count());
        $this->assertEquals(126, Stock::sum('quantity'));
        $this->assertEquals(21, $this->order->items()->first()->quantity_received);
    }

    public function test_po_return_can_be_fulfilled_by_multiple_expiry_batches(): void
    {
        $this->setupOrder();
        $this->post(route('purchase.receipts.store'), $this->payload($this->order, 90, 0, 0, 10))->assertSessionHasNoErrors();
        $return = $this->claim(PurchaseReceipt::first());
        $this->post(route('purchase.returns.approve', $return))->assertSessionHasNoErrors();
        $replacement = $return->fresh()->replacementOrder;
        $data = $this->batchPayload($replacement, [
            ['quantity' => 6, 'location_id' => $this->location->id, 'expired_date' => today()->addMonths(2)->toDateString()],
            ['quantity' => 4, 'location_id' => $this->location->id, 'expired_date' => today()->addMonths(3)->toDateString()],
        ]);
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasNoErrors();
        $this->assertEquals('completed', $this->order->fresh()->status);
        $this->assertEquals('completed', $replacement->fresh()->status);
        $this->assertEquals(600, Stock::sum('quantity'));
        $this->assertEquals(100, $this->order->items()->first()->quantity_received);
        $this->assertDatabaseCount('stock_batches', 3);
    }

    public function test_partial_inspection_only_adds_good_stock_and_reserves_exceptions(): void
    {
        $this->setupOrder();
        $data = $this->payload($this->order, 90, 3, 2, 5);
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertEquals(540, Stock::sum('quantity'));
        $this->assertEquals(540, StockBatch::sum('quantity_remaining'));
        $this->assertDatabaseCount('stock_movements', 1);
        $item = $this->order->items()->first();
        $this->assertEquals(90, $item->quantity_received);
        $this->assertEquals(10, $item->quantity_reserved);
        $this->assertEquals(0, $item->receivableQuantity());
        $this->assertEquals('partial', $this->order->fresh()->status);
        $inspection = PurchaseInspection::first();
        $this->assertEquals(5, $inspection->quantity_missing);
        $this->get(route('purchase.receipts.show', PurchaseReceipt::first()))->assertOk()->assertSee('Buat Retur/Klaim');
        $this->get(route('purchase.receipts.create', ['order_id' => $this->order->id]))->assertOk()->assertSee('Tidak ada sisa penerimaan');
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('purchase_receipts', 1);
        $this->assertEquals(540, Stock::sum('quantity'));
    }

    public function test_claim_selector_hides_completed_orders_even_when_old_inspections_have_unclaimed_issues(): void
    {
        $this->setupOrder();
        $this->post(route('purchase.receipts.store'), $this->payload($this->order, 90, 10))->assertSessionHasNoErrors();
        $receipt = PurchaseReceipt::first();
        $this->get(route('purchase.returns.create'))->assertOk()
            ->assertViewHas('receipts', fn ($receipts) => $receipts->contains('id', $receipt->id));
        $this->order->update(['status' => 'completed']);
        $this->get(route('purchase.returns.create'))->assertOk()
            ->assertViewHas('receipts', fn ($receipts) => $receipts->isEmpty());
        $this->get(route('purchase.returns.create', ['receipt_id' => $receipt->id]))
            ->assertRedirect(route('purchase.returns.create'))->assertSessionHas('error');
        $this->assertDatabaseHas('purchase_receipts', ['id' => $receipt->id]);
        $this->get(route('purchase.receipts.show', $receipt))->assertOk();
    }

    public function test_claim_selector_keeps_partial_claims_hides_fully_claimed_and_restores_rejected_quantities(): void
    {
        $this->setupOrder();
        $this->post(route('purchase.receipts.store'), $this->payload($this->order, 90, 0.9, 0, 9.1))->assertSessionHasNoErrors();
        $receipt = PurchaseReceipt::first();
        $inspection = $receipt->inspections()->first();
        foreach ([0.3, 0.6] as $quantity) {
            $this->post(route('purchase.returns.store'), [
                'purchase_receipt_id' => $receipt->id,
                'items' => [['purchase_inspection_id' => $inspection->id, 'kind' => 'damaged', 'quantity' => $quantity]],
            ])->assertSessionHasNoErrors();
        }
        $this->get(route('purchase.returns.create'))->assertOk()
            ->assertViewHas('receipts', fn ($receipts) => $receipts->contains('id', $receipt->id));
        $return = $this->claim($receipt);
        $this->assertEquals('partial', $this->order->fresh()->status);
        $this->get(route('purchase.returns.create'))->assertOk()
            ->assertViewHas('receipts', fn ($receipts) => $receipts->isEmpty());
        $this->post(route('purchase.returns.reject', $return), ['rejection_reason' => 'Perbaiki keterangan'])
            ->assertSessionHasNoErrors();
        $this->get(route('purchase.returns.create', ['receipt_id' => $receipt->id]))->assertOk()
            ->assertViewHas('receipts', fn ($receipts) => $receipts->contains('id', $receipt->id));
        $return = $this->claim($receipt->fresh());
        $this->post(route('purchase.returns.approve', $return))->assertSessionHasNoErrors();
        $this->get(route('purchase.returns.create'))->assertOk()
            ->assertViewHas('receipts', fn ($receipts) => $receipts->isEmpty());
    }

    public function test_return_numbers_restart_monthly_and_stay_linked_to_the_original_po(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-17 10:00:00','Asia/Jakarta'));
        $this->setupOrder();
        $this->order->update(['po_number'=>'PO/2026/10/000001']);
        $this->post(route('purchase.receipts.store'),$this->payload($this->order,90,10))->assertSessionHasNoErrors();
        $receipt=PurchaseReceipt::first();
        $inspection=$receipt->inspections()->first();
        $legacy=PurchaseReturn::create(['return_number'=>'RT-2026-000999','purchase_receipt_id'=>$receipt->id,'created_by'=>$this->owner->id]);
        $data=['purchase_receipt_id'=>$receipt->id,'items'=>[
            ['purchase_inspection_id'=>$inspection->id,'kind'=>'damaged','quantity'=>1],
        ]];
        foreach (['RT/2026/10/000001','RT/2026/10/000002'] as $number) {
            $data['submission_token']=(string) Str::uuid();
            $this->post(route('purchase.returns.store'),$data)->assertSessionHasNoErrors()->assertRedirect();
            $return=PurchaseReturn::latest('id')->first();
            $this->assertSame($number,$return->return_number);
            $this->post(route('purchase.returns.store'),$data)->assertSessionHasNoErrors();
            $this->assertSame($return->id,PurchaseReturn::latest('id')->first()->id);
        }
        $this->travelTo(\Carbon\Carbon::parse('2026-11-01 10:00:00','Asia/Jakarta'));
        $data['submission_token']=(string) Str::uuid();
        $this->post(route('purchase.returns.store'),$data)->assertSessionHasNoErrors();
        $november=PurchaseReturn::latest('id')->first();
        $this->assertSame('RT/2026/11/000001',$november->return_number);
        $this->assertSame('RT/2026/11/000002',PurchaseReturn::generateReturnNumber());
        $this->assertSame('RT/2027/01/000001',PurchaseReturn::generateReturnNumber(\Carbon\Carbon::parse('2027-01-01','Asia/Jakarta')));
        $this->post(route('purchase.returns.approve',$november))->assertSessionHasNoErrors();
        $this->assertSame('PO/R/2026/10/000001',$november->fresh()->replacementOrder->po_number);
        $this->assertSame('RT/2026/11/000001',$november->fresh()->return_number);
        $this->assertSame('RT-2026-000999',$legacy->fresh()->return_number);
        $this->assertSame('RT/2026/10/000002',$return->fresh()->return_number);
        $this->get(route('purchase.returns.index'))->assertOk()->assertSee($november->return_number)->assertSee($legacy->return_number);
        $this->get(route('purchase.returns.show',$november))->assertOk()->assertSee($november->return_number)->assertSee('PO/R/2026/10/000001');
    }

    public function test_return_number_uses_the_highest_monthly_sequence_and_ignores_other_periods(): void
    {
        $this->setupOrder();
        $this->post(route('purchase.receipts.store'),$this->payload($this->order,90,10))->assertSessionHasNoErrors();
        $receipt=PurchaseReceipt::first();
        foreach (['RT/2026/10/000100','RT/2026/10/000003','RT-2026-000999','RT/2026/09/000999','RT/2025/10/000999'] as $number) {
            PurchaseReturn::create(['return_number'=>$number,'purchase_receipt_id'=>$receipt->id,'created_by'=>$this->owner->id]);
        }
        $this->assertSame('RT/2026/10/000101',PurchaseReturn::generateReturnNumber(\Carbon\Carbon::parse('2026-10-17','Asia/Jakarta')));
        $this->assertSame('RT/2026/12/000001',PurchaseReturn::generateReturnNumber(\Carbon\Carbon::parse('2026-12-01','Asia/Jakarta')));
    }

    public function test_return_number_uses_jakarta_issue_time_without_mutating_it(): void
    {
        $issuedAt=\Carbon\Carbon::parse('2026-12-31 17:01:00','UTC');
        $this->assertSame('RT/2027/01/000001',PurchaseReturn::generateReturnNumber($issuedAt));
        $this->assertSame('2026-12-31 17:01:00 UTC',$issuedAt->format('Y-m-d H:i:s T'));
    }

    public function test_approval_and_replacement_fulfill_original_without_duplicate_value_or_stock(): void
    {
        $this->setupOrder();
        $this->post(route('purchase.receipts.store'), $this->payload($this->order, 90, 3, 2, 5))->assertSessionHasNoErrors();
        $this->capturePage('claim-form', $this->get(route('purchase.returns.create', ['receipt_id' => PurchaseReceipt::first()->id]))->assertOk());
        $return = $this->claim(PurchaseReceipt::first());
        $this->capturePage('claim-draft', $this->get(route('purchase.returns.show', $return))->assertOk());
        $this->get(route('purchase.returns.index'))->assertOk()->assertSee($return->return_number)
            ->assertSee('PO Retur')->assertDontSee('PO Tindak Lanjut');
        $this->get(route('purchase.returns.create', ['receipt_id' => $return->purchase_receipt_id]))
            ->assertRedirect(route('purchase.returns.create'))->assertSessionHas('error');
        $this->post(route('purchase.returns.approve', $return))->assertSessionHasNoErrors();
        $replacement = $return->fresh()->replacementOrder;
        $this->capturePage('claim-approved', $this->get(route('purchase.returns.show', $return))->assertOk());
        $this->assertEquals('PO/R-2026-000001', $replacement->po_number);
        $this->assertEquals(0, $replacement->totalValue());
        $this->assertEquals(1200000, PurchaseOrder::with('items')->get()->sum(fn ($po) => $po->totalValue()));
        $this->assertEquals(540, Stock::sum('quantity'));
        $this->post(route('purchase.returns.approve', $return))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('purchase_orders', 2);
        $this->get(route('purchase.returns.show', $return))->assertOk()->assertSee('Catat Penerimaan')
            ->assertSee('PO Retur:')->assertDontSee('PO Tindak Lanjut')
            ->assertDontSee('Penyerahan Retur Fisik')->assertDontSee('Catat Sudah Diserahkan');
        $this->get(route('purchase.orders.show', $replacement))->assertOk()->assertSee('Tanpa tagihan tambahan');
        $pdf = view('purchase.orders.pdf', ['order' => $replacement->load('items.product', 'supplier', 'createdBy')])->render();
        $this->assertStringContainsString('TANPA TAGIHAN TAMBAHAN', $pdf);
        $this->assertStringContainsString('PO RETUR', $pdf);
        $this->assertStringNotContainsString('PO TINDAK LANJUT', $pdf);
        $this->get(route('purchase.orders.pdf', $replacement))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('purchase.orders.excel', $replacement))->assertOk();
        $this->post(route('purchase.returns.dispatch', $return))->assertSessionHasNoErrors();
        $this->assertNotNull($return->fresh()->dispatched_at);
        $this->get(route('purchase.returns.show', $return))->assertOk()
            ->assertDontSee('Penyerahan Retur Fisik')->assertDontSee('Catat Sudah Diserahkan');
        $this->assertEquals(540, Stock::sum('quantity'));
        $this->post(route('purchase.receipts.store'), $this->payload($replacement, 10))->assertSessionHasNoErrors();
        $this->assertEquals(600, Stock::sum('quantity'));
        $this->assertEquals(600, StockBatch::sum('quantity_remaining'));
        $this->assertEquals('completed', $this->order->fresh()->status);
        $this->assertEquals('completed', $replacement->fresh()->status);
        $this->assertEquals(0, $this->order->items()->first()->quantity_reserved);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->get(route('report.purchase'))->assertOk();
    }

    public function test_monthly_po_returns_follow_the_root_number_across_months_and_keep_unique_suffixes(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-04 10:00:00','Asia/Jakarta'));
        $this->setupOrder();
        $this->order->update(['po_number'=>'PO/2026/10/000001']);
        $this->post(route('purchase.receipts.store'), $this->payload($this->order, 90, 5, 0, 5))->assertSessionHasNoErrors();
        $return=$this->claim(PurchaseReceipt::first());
        $this->post(route('purchase.returns.approve',$return))->assertSessionHasNoErrors();
        $replacement=$return->fresh()->replacementOrder;
        $this->assertSame('PO/R/2026/10/000001',$replacement->po_number);
        $this->assertEquals($this->order->id,$replacement->root_purchase_order_id);
        $this->assertSame('PO/2026/11/000001',PurchaseOrder::generatePoNumber());
        $this->post(route('purchase.returns.approve',$return))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('purchase_orders',2);
        $this->get(route('purchase.returns.show',$return))->assertOk()->assertSee($replacement->po_number);
        $this->get(route('purchase.orders.index'))->assertOk()->assertSee($this->order->po_number)->assertSee($replacement->po_number);
        $this->get(route('purchase.orders.show',$replacement))->assertOk()->assertSee($replacement->po_number);
        $pdf=$this->get(route('purchase.orders.pdf',$replacement))->assertOk()->assertHeader('content-type','application/pdf');
        $this->assertStringContainsString('PO-R-2026-10-000001.pdf',$pdf->headers->get('Content-Disposition'));
        $excel=$this->get(route('purchase.orders.excel',$replacement))->assertOk();
        $this->assertStringContainsString('PO-R-2026-10-000001.xlsx',$excel->headers->get('Content-Disposition'));
        $this->post(route('purchase.receipts.store'),$this->payload($replacement,5,2,0,3))->assertSessionHasNoErrors();
        $second=$this->claim(PurchaseReceipt::latest('id')->first());
        $this->post(route('purchase.returns.approve',$second))->assertSessionHasNoErrors();
        $next=$second->fresh()->replacementOrder;
        $this->assertSame('PO/R/2026/10/000001-02',$next->po_number);
        $this->post(route('purchase.receipts.store'),$this->payload($next,5))->assertSessionHasNoErrors();
        $this->assertSame('completed',$this->order->fresh()->status);
        $this->assertSame('PO/2026/10/000001',$this->order->fresh()->po_number);
        $this->assertEquals(600,Stock::sum('quantity'));
    }

    public function test_replacement_can_itself_be_partial_and_replaced_with_unique_suffix(): void
    {
        $this->setupOrder();
        $this->post(route('purchase.receipts.store'), $this->payload($this->order, 90, 5, 0, 5))->assertSessionHasNoErrors();
        $return = $this->claim(PurchaseReceipt::first());
        $this->post(route('purchase.returns.approve', $return))->assertSessionHasNoErrors();
        $replacement = $return->fresh()->replacementOrder;
        $this->post(route('purchase.receipts.store'), $this->payload($replacement, 5, 2, 0, 3))->assertSessionHasNoErrors();
        $second = $this->claim(PurchaseReceipt::latest('id')->first());
        $this->post(route('purchase.returns.approve', $second))->assertSessionHasNoErrors();
        $next = $second->fresh()->replacementOrder;
        $this->assertEquals('PO/R-2026-000001-02', $next->po_number);
        $this->assertEquals(95, $this->order->items()->first()->quantity_received);
        $this->assertEquals(5, $this->order->items()->first()->quantity_reserved);
        $this->post(route('purchase.receipts.store'), $this->payload($next, 5))->assertSessionHasNoErrors();
        foreach ([$this->order, $replacement, $next] as $po) {
            $this->assertEquals('completed', $po->fresh()->status);
            $this->assertEquals(0, $po->items()->first()->quantity_reserved);
        }
        $this->assertEquals(600, Stock::sum('quantity'));
    }

    public function test_reserved_items_cannot_be_received_again_from_parent_and_claim_cannot_repeat(): void
    {
        $this->setupOrder();
        $this->post(route('purchase.receipts.store'), $this->payload($this->order, 90, 0, 0, 10))->assertSessionHasNoErrors();
        $this->post(route('purchase.receipts.store'), $this->payload($this->order, 1))->assertSessionHasErrors('items.0.quantity_received');
        $return = $this->claim(PurchaseReceipt::first());
        $data = ['purchase_receipt_id' => $return->purchase_receipt_id, 'items' => [['purchase_inspection_id' => PurchaseInspection::first()->id, 'kind' => 'missing', 'quantity' => 10]]];
        $this->post(route('purchase.returns.store'), $data)->assertSessionHasErrors('items.0.quantity');
        $this->assertDatabaseCount('purchase_returns', 1);
        $this->post(route('purchase.returns.reject', $return), ['rejection_reason' => 'Jumlah dikoreksi'])->assertSessionHasNoErrors();
        $this->post(route('purchase.returns.store'), $data)->assertSessionHasNoErrors();
        $new = PurchaseReturn::latest('id')->first();
        $this->post(route('purchase.returns.approve', $return))->assertSessionHasErrors('return');
        $this->post(route('purchase.returns.approve', $new))->assertSessionHasNoErrors();
        $this->post(route('purchase.returns.dispatch', $new))->assertSessionHasErrors('return');
        $this->assertEquals(540, Stock::sum('quantity'));
    }

    public function test_exception_only_receipt_and_uninspected_remainder_are_supported(): void
    {
        $this->setupOrder();
        $data = $this->payload($this->order, 0, 4, 0, 6);
        unset($data['items'][0]['expired_date'],$data['items'][0]['allocations']);
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('stocks', 0);
        $this->assertDatabaseCount('stock_batches', 0);
        $this->assertDatabaseCount('purchase_receipt_items', 0);
        $this->assertEquals(90, $this->order->items()->first()->receivableQuantity());
        $this->get(route('purchase.receipts.show', PurchaseReceipt::first()))->assertOk()->assertSee('Hasil Pemeriksaan');
        $this->assertFalse($this->order->fresh()->canModify());
        $this->delete(route('purchase.orders.destroy', $this->order))->assertSessionHas('error');
    }

    public function test_invalid_inspections_roll_back_and_require_notes_quarantine_and_expiry(): void
    {
        $this->setupOrder();
        $data = $this->payload($this->order, 90, 11);
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasErrors('items.0.quantity_received');
        $data = $this->payload($this->order, 0, 1);
        unset($data['items'][0]['reason']);
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasErrors('items.0.reason');
        $data = $this->payload($this->order, 0, 1);
        unset($data['items'][0]['quarantine_location_id']);
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasErrors('items.0.quarantine_location_id');
        $data = $this->payload($this->order, 1);
        unset($data['items'][0]['expired_date']);
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasErrors('items.0.expired_date');
        $data = $this->payload($this->order, 0);
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasErrors('items');
        $data = $this->payload($this->order, 1);
        $data['items'][] = $data['items'][0];
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasErrors('items.0.purchase_order_item_id');
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->assertDatabaseCount('stocks', 0);
        $this->assertDatabaseCount('purchase_inspections', 0);
    }

    public function test_warehouse_can_create_and_dispatch_but_not_approve_or_reject_cashier_cannot_access(): void
    {
        $this->setupOrder();
        $this->post(route('purchase.receipts.store'), $this->payload($this->order, 90, 10))->assertSessionHasNoErrors();
        $warehouse = User::create(['name' => 'Gudang', 'email' => 'warehouse-return@example.com', 'password' => 'password', 'role' => 'staf_gudang']);
        $this->actingAs($warehouse);
        $this->get(route('purchase.returns.create', ['receipt_id' => PurchaseReceipt::first()->id]))->assertOk();
        $return = $this->claim(PurchaseReceipt::first());
        $this->post(route('purchase.returns.approve', $return))->assertForbidden();
        $this->post(route('purchase.returns.reject', $return), ['rejection_reason' => 'Tidak sesuai'])->assertForbidden();
        $this->actingAs($this->owner)->post(route('purchase.returns.approve', $return))->assertSessionHasNoErrors();
        $this->actingAs($warehouse)->get(route('purchase.returns.show', $return))->assertOk()->assertDontSee('Detail PO');
        $this->post(route('purchase.returns.dispatch', $return))->assertSessionHasNoErrors();
        $cashier = User::create(['name' => 'Kasir', 'email' => 'cashier-return@example.com', 'password' => 'password', 'role' => 'kasir']);
        $this->actingAs($cashier)->get(route('purchase.returns.index'))->assertForbidden();
        $this->post(route('purchase.returns.approve', $return))->assertForbidden();
    }

    public function test_partial_claims_and_repeated_form_submission_do_not_duplicate_documents(): void
    {
        $this->setupOrder();
        $this->post(route('purchase.receipts.store'), $this->payload($this->order, 80, 10, 0, 10))->assertSessionHasNoErrors();
        $inspection = PurchaseInspection::first();
        $data = ['submission_token' => (string) Str::uuid(), 'purchase_receipt_id' => $inspection->purchase_receipt_id,
            'items' => [['purchase_inspection_id' => $inspection->id, 'kind' => 'damaged', 'quantity' => 4]]];
        $this->post(route('purchase.returns.store'), $data)->assertSessionHasNoErrors();
        $this->post(route('purchase.returns.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('purchase_returns', 1);
        $this->assertEquals(6, $inspection->claimable('damaged'));
        $this->assertEquals(10, $inspection->claimable('missing'));
        $first = PurchaseReturn::first();
        $this->post(route('purchase.returns.approve', $first))->assertSessionHasNoErrors();
        $this->post(route('purchase.receipts.store'), $this->payload($first->fresh()->replacementOrder, 4))->assertSessionHasNoErrors();
        $this->assertEquals(84, $this->order->items()->first()->quantity_received);
        $this->assertEquals(16, $this->order->items()->first()->quantity_reserved);
        $this->assertEquals(0, $this->order->items()->first()->receivableQuantity());
        $second = $this->claim(PurchaseReceipt::first());
        $this->post(route('purchase.returns.approve', $second))->assertSessionHasNoErrors();
        $this->assertEquals('PO/R-2026-000001-02', $second->fresh()->replacementOrder->po_number);
    }

    public function test_zero_rows_are_skipped_and_multiple_good_allocations_remain_supported(): void
    {
        $this->setupOrder();
        $data = $this->payload($this->order, 4.5);
        $other = Location::create(['name' => 'Toko']);
        $data['items'][0]['allocations'] = [['location_id' => $this->location->id, 'quantity' => 2], ['location_id' => $other->id, 'quantity' => 2.5]];
        $item = $this->order->items()->first();
        $second = $this->order->items()->create(['product_id' => $item->product_id, 'product_unit_id' => $item->product_unit_id, 'quantity_ordered' => 5, 'buy_price' => 12000]);
        $data['items'][1] = $this->payload($this->order, 0)['items'][0];
        $data['items'][1]['purchase_order_item_id'] = $second->id;
        unset($data['items'][1]['expired_date'],$data['items'][1]['allocations']);
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('purchase_inspections', 1);
        $this->assertDatabaseCount('purchase_receipt_items', 2);
        $this->assertEquals(27, Stock::sum('quantity'));
        $this->assertEquals(95.5, $item->fresh()->receivableQuantity());
        $this->assertEquals(0, $second->fresh()->quantity_received);
    }

    public function test_foreign_order_items_and_inspections_are_rejected_atomically(): void
    {
        $this->setupOrder();
        $other = PurchaseOrder::create(['po_number' => 'PO-2026-000002', 'supplier_id' => $this->order->supplier_id,
            'order_date' => today(), 'created_by' => $this->owner->id, 'status' => 'pending']);
        $item = $this->order->items()->first();
        $otherItem = $other->items()->create(['product_id' => $item->product_id, 'product_unit_id' => $item->product_unit_id,
            'quantity_ordered' => 100, 'buy_price' => 12000]);
        $data = $this->payload($this->order, 1);
        $data['items'][0]['purchase_order_item_id'] = $otherItem->id;
        $this->post(route('purchase.receipts.store'), $data)->assertSessionHasErrors('items.0');
        $this->assertDatabaseCount('purchase_receipts', 0);
        $this->post(route('purchase.receipts.store'), $this->payload($this->order, 90, 10))->assertSessionHasNoErrors();
        $this->post(route('purchase.receipts.store'), $this->payload($other, 90, 10))->assertSessionHasNoErrors();
        $first = PurchaseReceipt::first();
        $inspection = PurchaseInspection::latest('id')->first();
        $data = ['purchase_receipt_id' => $first->id, 'items' => [['purchase_inspection_id' => $inspection->id, 'kind' => 'damaged', 'quantity' => 1]]];
        $this->post(route('purchase.returns.store'), $data)->assertSessionHasErrors('items.0');
        $this->assertDatabaseCount('purchase_returns', 0);
        $this->assertEquals(1080, Stock::sum('quantity'));
    }

    public function test_replacement_cannot_be_edited_or_deleted_and_pdf_excel_have_safe_filenames(): void
    {
        $this->setupOrder();
        $this->post(route('purchase.receipts.store'), $this->payload($this->order, 0, 0, 0, 100))->assertSessionHasNoErrors();
        $return = $this->claim(PurchaseReceipt::first());
        $this->post(route('purchase.returns.approve', $return))->assertSessionHasNoErrors();
        $replacement = $return->fresh()->replacementOrder;
        $this->get(route('purchase.orders.edit', $replacement))->assertSessionHas('error');
        $this->put(route('purchase.orders.update', $replacement), [])->assertSessionHas('error');
        $this->delete(route('purchase.orders.destroy', $replacement))->assertSessionHas('error');
        $this->assertDatabaseCount('purchase_orders', 2);
        $this->assertEquals(0, $replacement->totalValue());
        $this->assertDatabaseCount('stocks', 0);
        $this->get(route('purchase.orders.pdf', $replacement))->assertOk()
            ->assertHeader('content-disposition', 'inline; filename=PO-R-2026-000001.pdf');
    }
}
