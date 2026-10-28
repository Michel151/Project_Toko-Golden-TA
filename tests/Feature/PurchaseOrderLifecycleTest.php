<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, ProductUnit, PurchaseOrder, Sale, SaleItem, Supplier, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PurchaseOrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Product $product;
    private ProductUnit $unit;
    private Supplier $supplier;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-28 10:00:00', 'Asia/Jakarta'));
        $this->owner = User::create(['name'=>'Owner PO', 'email'=>'po-lifecycle@test.com', 'password'=>'password', 'role'=>'owner']);
        $this->actingAs($this->owner);
        $category = Category::create(['name'=>'Uji PO']);
        $base = Unit::create(['name'=>'Botol']);
        $this->product = Product::create(['name'=>'Minuman Uji PO', 'barcode'=>'PO-LIFECYCLE', 'category_id'=>$category->id,
            'buy_unit_id'=>$base->id, 'buy_price'=>50000, 'sell_price_wholesale'=>65000, 'is_active'=>true, 'is_expirable'=>false]);
        $this->unit = ProductUnit::create(['product_id'=>$this->product->id, 'unit_id'=>$base->id, 'conversion_to_base'=>1,
            'buy_price'=>50000, 'sell_price'=>65000, 'is_base_unit'=>true, 'is_default_purchase'=>true, 'is_default_sale'=>true]);
        $this->supplier = Supplier::create(['name'=>'Supplier Uji PO']);
        $this->supplier->products()->attach($this->product->id, ['buy_price'=>50000]);
        $this->location = Location::create(['name'=>'Toko']);
        DB::table('inventory_cost_settings')->where('id',1)->update(['automatic_from_zero'=>true]);
    }

    private function payload(string $intent = 'draft'): array
    {
        return ['intent'=>$intent, 'supplier_id'=>$this->supplier->id, 'order_date'=>'2026-10-28', 'items'=>[
            ['product_id'=>$this->product->id, 'product_unit_id'=>$this->unit->id, 'quantity_ordered'=>10, 'buy_price'=>'50000'],
        ]];
    }

    private function createOrder(string $intent = 'draft'): PurchaseOrder
    {
        $this->post(route('purchase.orders.store'), $this->payload($intent))->assertSessionHasNoErrors();
        return PurchaseOrder::latest('id')->firstOrFail();
    }

    private function receiptPayload(PurchaseOrder $order, float $quantity, ?string $token = null): array
    {
        $item = $order->items()->firstOrFail();
        return ['purchase_order_id'=>$order->id, 'receipt_date'=>'2026-10-28', 'submission_token'=>$token, 'items'=>[
            ['purchase_order_item_id'=>$item->id, 'product_id'=>$this->product->id, 'product_unit_id'=>$this->unit->id,
                'quantity_received'=>$quantity, 'allocations'=>[['location_id'=>$this->location->id, 'quantity'=>$quantity]]],
        ]];
    }

    private function costs(): array
    {
        $tables = ['stocks', 'stock_batches', 'stock_movements', 'inventory_cost_balances', 'inventory_cost_entries', 'sale_items', 'products', 'product_units', 'product_supplier'];
        return collect($tables)->mapWithKeys(fn ($table)=>[$table=>DB::table($table)->orderBy(\Illuminate\Support\Facades\Schema::getColumnListing($table)[0])->get()->toJson()])->all();
    }

    private function capture(string $name, $response): void
    {
        if (getenv('CAPTURE_PO_LIFECYCLE_UI') !== '1') return;
        $directory = storage_path('framework/testing/po-lifecycle');
        if (!is_dir($directory)) mkdir($directory, 0777, true);
        file_put_contents($directory.'/'.$name.'.html', $response->getContent());
    }

    public function test_draft_can_be_edited_confirmed_and_then_locked_without_affecting_costs(): void
    {
        $before = $this->costs();
        $draft = $this->createOrder();
        $this->assertSame('draft', $draft->status);
        $this->assertTrue($draft->canModify());
        $this->assertNull($draft->confirmed_at);
        $this->capture('draft', $this->get(route('purchase.orders.show', $draft))->assertOk()->assertSee('Konfirmasi PO')->assertDontSee('>Terima Barang<', false));
        $this->capture('edit', $this->get(route('purchase.orders.edit', $draft))->assertOk()->assertSee('Simpan Draf')->assertSee('Konfirmasi PO'));
        $data = $this->payload();
        $data['items'][0]['buy_price'] = '49.500,50';
        $data['items'][0]['quantity_ordered'] = 12;
        $this->put(route('purchase.orders.update', $draft), $data)->assertSessionHasNoErrors();
        $this->assertEquals('49500.50', $draft->items()->first()->buy_price);
        $itemId = $draft->items()->first()->id;
        $this->post(route('purchase.orders.confirm', $draft))->assertSessionHasNoErrors();
        $draft->refresh();
        $this->assertSame('pending', $draft->status);
        $this->assertEquals($this->owner->id, $draft->confirmed_by);
        $this->assertNotNull($draft->confirmed_at);
        $this->assertSame($itemId, $draft->items()->first()->id);
        $this->assertFalse($draft->canModify());
        $this->get(route('purchase.orders.edit', $draft))->assertSessionHas('error');
        $this->put(route('purchase.orders.update', $draft), $data)->assertSessionHas('error');
        $this->assertSame($itemId, $draft->items()->first()->id);
        $this->assertSame($before, $this->costs());
        $confirmedAt = $draft->confirmed_at->toISOString();
        $this->post(route('purchase.orders.confirm', $draft))->assertSessionHasNoErrors();
        $this->assertSame($confirmedAt, $draft->fresh()->confirmed_at->toISOString());
        $this->capture('confirmed', $this->get(route('purchase.orders.show', $draft))->assertOk());
    }

    public function test_direct_confirmation_and_edit_then_confirmation_both_preserve_po_price(): void
    {
        $before = $this->costs();
        $confirmed = $this->createOrder('confirm');
        $this->assertSame('pending', $confirmed->status);
        $this->assertNotNull($confirmed->confirmed_at);
        $draft = $this->createOrder();
        $data = $this->payload('confirm');
        $data['items'][0]['buy_price'] = '47.500,25';
        $this->put(route('purchase.orders.update', $draft), $data)->assertSessionHasNoErrors();
        $this->assertSame('pending', $draft->fresh()->status);
        $this->assertEquals('47500.25', $draft->items()->first()->buy_price);
        $this->assertSame($before, $this->costs());
    }

    public function test_cancel_requires_reason_preserves_document_and_prevents_receipt_or_reopening(): void
    {
        $draft = $this->createOrder();
        $before = $this->costs();
        $items = $draft->items()->get()->toJson();
        $this->post(route('purchase.orders.cancel', $draft), ['reason'=>'  '])->assertSessionHasErrors('reason');
        $this->post(route('purchase.orders.cancel', $draft), ['reason'=>'Pesanan ganda'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $draft->fresh()->status);
        $this->assertEquals($this->owner->id, $draft->fresh()->cancelled_by);
        $this->assertSame('Pesanan ganda', $draft->fresh()->cancellation_reason);
        $this->assertSame($items, $draft->items()->get()->toJson());
        $this->post(route('purchase.orders.confirm', $draft))->assertStatus(409);
        $this->post(route('purchase.receipts.store'), $this->receiptPayload($draft, 1))->assertSessionHasErrors('purchase_order_id');
        $this->get(route('purchase.receipts.create', ['order_id'=>$draft->id]))->assertStatus(409);
        $this->get(route('purchase.receipts.order-items', $draft))->assertStatus(409);
        $this->assertSame(0, $draft->receipts()->count());
        $this->post(route('purchase.orders.cancel', $draft), ['reason'=>'Alasan baru'])->assertSessionHasNoErrors();
        $this->assertSame('Pesanan ganda', $draft->fresh()->cancellation_reason);
        $this->assertSame($before, $this->costs());
        $this->capture('cancelled', $this->get(route('purchase.orders.show', $draft))->assertOk()->assertSee('Pesanan ganda'));
        $this->assertDatabaseHas('audit_logs', ['module'=>'Purchase Order', 'auditable_id'=>$draft->id, 'action'=>'updated']);
    }

    public function test_only_owner_may_cancel_confirmed_orders_but_granted_staff_can_cancel_drafts(): void
    {
        $draft = $this->createOrder();
        $confirmed = $this->createOrder('confirm');
        $staff = User::create(['name'=>'Staf PO', 'email'=>'po-staff@test.com', 'password'=>'password', 'role'=>'staf_gudang']);
        DB::table('role_permissions')->where('role', 'staf_gudang')->delete();
        foreach (['view', 'create', 'edit', 'delete'] as $action) DB::table('role_permissions')->insert(['role'=>'staf_gudang', 'permission'=>'purchase.orders.'.$action]);
        $this->actingAs($staff);
        $this->post(route('purchase.orders.confirm', $draft))->assertForbidden();
        $this->post(route('purchase.orders.store'), $this->payload('confirm'))->assertForbidden();
        $this->put(route('purchase.orders.update', $draft), $this->payload('confirm'))->assertForbidden();
        $this->post(route('purchase.orders.cancel', $confirmed), ['reason'=>'Supplier batal'])->assertForbidden();
        $this->assertSame('pending', $confirmed->fresh()->status);
        $this->post(route('purchase.orders.cancel', $draft), ['reason'=>'Draf salah'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $draft->fresh()->status);
        $this->actingAs($this->owner)->post(route('purchase.orders.cancel', $confirmed), ['reason'=>'Supplier tidak tersedia'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $confirmed->fresh()->status);
    }

    public function test_draft_cannot_be_received_and_confirmation_validates_current_master_data(): void
    {
        $draft = $this->createOrder();
        $this->post(route('purchase.receipts.store'), $this->receiptPayload($draft, 1))->assertSessionHasErrors('purchase_order_id');
        $this->get(route('purchase.receipts.order-items', $draft))->assertStatus(409);
        $this->assertSame(0, $draft->receipts()->count());
        $this->supplier->products()->detach($this->product->id);
        $this->post(route('purchase.orders.confirm', $draft))->assertSessionHasErrors('items');
        $this->assertSame('draft', $draft->fresh()->status);
        $this->supplier->products()->attach($this->product->id, ['buy_price'=>50000]);
        $this->product->update(['is_active'=>false]);
        $this->post(route('purchase.orders.confirm', $draft))->assertSessionHasErrors('items');
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_partial_receipt_can_only_close_remaining_order_and_preserves_hpp_and_history(): void
    {
        $order = $this->createOrder('confirm');
        $token = (string) Str::uuid();
        $receipt = $this->receiptPayload($order, 4, $token);
        $this->post(route('purchase.receipts.store'), $receipt)->assertSessionHasNoErrors();
        $this->assertSame('partial', $order->fresh()->status);
        $this->assertDatabaseHas('inventory_cost_balances', ['product_id'=>$this->product->id, 'quantity'=>4, 'inventory_value'=>200000]);
        $sale = Sale::create(['sale_number'=>'INV-LEGACY-KEEP', 'sale_date'=>today(), 'status'=>'paid', 'payment_method'=>'tunai', 'total_amount'=>65000, 'created_by'=>$this->owner->id]);
        SaleItem::create(['sale_id'=>$sale->id, 'product_id'=>$this->product->id, 'quantity'=>1, 'unit_price'=>65000, 'subtotal'=>65000,
            'cost_price'=>50000, 'cost_subtotal'=>50000, 'cost_method'=>'moving_average', 'sale_type'=>'grosir']);
        $before = $this->costs();
        $items = $order->items()->get()->toJson();
        $receipts = DB::table('purchase_receipts')->get()->toJson();
        $this->post(route('purchase.orders.cancel', $order), ['reason'=>'Batal'])->assertStatus(409);
        $this->post(route('purchase.orders.close-remainder', $order), [])->assertSessionHasErrors('reason');
        $this->post(route('purchase.orders.close-remainder', $order), ['reason'=>'Sisa barang tidak tersedia'])->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame('closed', $order->status);
        $this->assertEquals($this->owner->id, $order->closed_by);
        $order->updateStatus();
        $this->assertSame('closed', $order->fresh()->status);
        $this->post(route('purchase.receipts.store'), $this->receiptPayload($order, 1))->assertSessionHasErrors('purchase_order_id');
        $this->post(route('purchase.receipts.store'), $receipt)->assertSessionHasNoErrors();
        $this->assertSame($before, $this->costs());
        $this->assertSame($items, $order->items()->get()->toJson());
        $this->assertSame($receipts, DB::table('purchase_receipts')->get()->toJson());
        $this->capture('closed', $this->get(route('purchase.orders.show', $order))->assertOk()->assertSee('Sisa barang tidak tersedia'));
    }

    public function test_unresolved_claims_completed_and_replacement_orders_cannot_be_closed_or_cancelled(): void
    {
        $order = $this->createOrder('confirm');
        $this->post(route('purchase.receipts.store'), $this->receiptPayload($order, 4))->assertSessionHasNoErrors();
        $item = $order->items()->first();
        $item->update(['quantity_reserved'=>2]);
        $this->post(route('purchase.orders.close-remainder', $order), ['reason'=>'Tutup'])->assertStatus(409);
        $item->update(['quantity_reserved'=>0]);
        $replacement = PurchaseOrder::create(['po_number'=>'PO/R/2026/10/000001', 'supplier_id'=>$this->supplier->id, 'order_date'=>'2026-10-28',
            'status'=>'pending', 'created_by'=>$this->owner->id, 'root_purchase_order_id'=>$order->id]);
        $this->post(route('purchase.orders.cancel', $replacement), ['reason'=>'Tutup'])->assertStatus(409);
        $this->post(route('purchase.orders.close-remainder', $order), ['reason'=>'Tutup'])->assertStatus(409);
        $complete = $this->createOrder('confirm');
        $this->post(route('purchase.receipts.store'), $this->receiptPayload($complete, 10))->assertSessionHasNoErrors();
        $this->post(route('purchase.orders.cancel', $complete), ['reason'=>'Tutup'])->assertStatus(409);
        $this->post(route('purchase.orders.close-remainder', $complete), ['reason'=>'Tutup'])->assertStatus(409);
        $this->assertSame('completed', $complete->fresh()->status);
    }

    public function test_reports_exclude_drafts_and_cancelled_orders_and_special_exports_identify_status(): void
    {
        $draft = $this->createOrder();
        $cancelled = $this->createOrder('confirm');
        $this->post(route('purchase.orders.cancel', $cancelled), ['reason'=>'Salah pesan'])->assertSessionHasNoErrors();
        $confirmed = $this->createOrder('confirm');
        $report = $this->get(route('report.purchase'))->assertOk();
        $this->assertSame(1, $report->viewData('orderCount'));
        $this->assertEquals(500000, $report->viewData('totalValue'));
        $this->assertSame([$confirmed->id], $report->viewData('orders')->pluck('id')->all());
        $index = $this->get(route('purchase.orders.index'))->assertOk();
        $this->assertSame(1, $index->viewData('draftCount'));
        $this->assertSame(1, $index->viewData('cancelledCount'));
        $this->assertEquals(500000, $index->viewData('totalOrderValue'));
        $this->capture('index', $index);
        $this->capture('create', $this->get(route('purchase.orders.create'))->assertOk());
        foreach ([$draft, $cancelled] as $order) {
            $order->refresh();
            $html = view('purchase.orders.pdf', ['order'=>$order->load(['supplier', 'createdBy', 'items.product.buyUnit', 'items.productUnit.unit'])])->render();
            $this->assertStringContainsString($order->status_label, $html);
            $this->get(route('purchase.orders.pdf', $order))->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $export = $this->get(route('purchase.orders.excel', $order))->assertOk();
            $book = IOFactory::load($export->baseResponse->getFile()->getPathname());
            $this->assertStringContainsString($order->status_label, $book->getActiveSheet()->getCell('A3')->getValue());
        }
    }

    public function test_validation_failure_preserves_all_draft_rows_and_manual_prices(): void
    {
        $data = $this->payload('confirm');
        $data['items'][0]['buy_price'] = '49000.25';
        $data['items'][1] = $data['items'][0];
        $data['order_date'] = 'invalid';
        $this->from(route('purchase.orders.create'))->post(route('purchase.orders.store'), $data)->assertSessionHasErrors('order_date');
        $page = $this->get(route('purchase.orders.create'))->assertOk();
        $this->assertSame(2, substr_count($page->getContent(), '<tr class="item-row">'));
        $page->assertSee('value="49000.25"', false);
        $this->assertDatabaseCount('purchase_orders', 0);
    }

}
