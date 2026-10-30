<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, ProductUnit, PurchaseOrder, PurchaseReceipt, PurchaseReturn, Stock, Supplier, Unit, User};
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReturnNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function receivingIssue(): array
    {
        $owner = User::create(['name'=>'Owner', 'email'=>'returns-notification@test.com', 'password'=>'password', 'role'=>'owner']);
        $this->actingAs($owner);
        $location = Location::create(['name'=>'Area Retur Gudang 1']);
        $unit = Unit::create(['name'=>'Dus']);
        $product = Product::create(['name'=>'Barang Retur', 'barcode'=>'RT-001', 'category_id'=>Category::create(['name'=>'Uji'])->id,
            'buy_unit_id'=>$unit->id, 'buy_price'=>1000, 'sell_price_wholesale'=>2000, 'is_active'=>true]);
        $productUnit = ProductUnit::create(['product_id'=>$product->id, 'unit_id'=>$unit->id, 'conversion_to_base'=>12, 'is_default_purchase'=>true]);
        Stock::create(['product_id'=>$product->id, 'location_id'=>$location->id, 'quantity'=>20]);
        $order = PurchaseOrder::create(['po_number'=>'PO-RETURN-NOTIF', 'supplier_id'=>Supplier::create(['name'=>'Supplier Retur'])->id,
            'order_date'=>today(), 'status'=>'partial', 'created_by'=>$owner->id]);
        $line = $order->items()->create(['product_id'=>$product->id, 'product_unit_id'=>$productUnit->id, 'quantity_ordered'=>10, 'buy_price'=>1000]);
        $receipt = PurchaseReceipt::create(['purchase_order_id'=>$order->id, 'receipt_date'=>today(), 'received_by'=>$owner->id]);
        $inspection = $receipt->inspections()->create(['purchase_order_item_id'=>$line->id, 'quantity_damaged'=>2,
            'quantity_wrong'=>1, 'quantity_missing'=>3, 'quarantine_location_id'=>$location->id]);
        return compact('owner', 'location', 'product', 'order', 'receipt', 'inspection');
    }

    public function test_unclaimed_issues_show_storage_supplier_and_permission_checked_receipt_actions(): void
    {
        extract($this->receivingIssue());
        $response = $this->get(route('notifications.index', ['condition'=>'return_pending']))->assertOk()
            ->assertSee('Perlu Retur/Klaim')->assertSee('Rusak 2 Dus')->assertSee('Tidak sesuai 1 Dus')
            ->assertSee('Kekurangan Kiriman 3 Dus')->assertSee('Supplier Retur')->assertSee('PO-RETURN-NOTIF')
            ->assertSee('Lokasi penyimpanan retur: Area Retur Gudang 1.')
            ->assertSee('Barang belum diterima; tidak memiliki lokasi penyimpanan.')
            ->assertSee(route('purchase.returns.create', ['receipt_id'=>$receipt->id]), false)
            ->assertSee(route('purchase.receipts.show', $receipt->id), false);
        $this->assertSame(1, $response->viewData('notifications')->total());
        $issues = collect($response->viewData('notifications')->first()['conditions'])->where('kind', 'return_pending');
        $this->assertCount(3, $issues);
        $this->assertSame([], $issues->firstWhere('issue_kind', 'missing')['location_ids']);
        $filtered = $this->get(route('notifications.index', ['condition'=>'return_pending', 'location_id'=>$location->id]))->assertOk();
        $this->assertStringContainsString('Lokasi penyimpanan retur', $filtered->viewData('notifications')->first()['summary']);

        $viewer = User::create(['name'=>'Viewer', 'email'=>'viewer-returns@test.com', 'password'=>'password', 'role'=>'staf_gudang']);
        DB::table('role_permissions')->where('role', 'staf_gudang')->delete();
        foreach (['inventory.stock.view', 'purchase.receipts.view', 'purchase.returns.view'] as $permission) {
            DB::table('role_permissions')->insert(['role'=>'staf_gudang', 'permission'=>$permission]);
        }
        $this->actingAs($viewer)->get(route('notifications.index', ['condition'=>'return_pending']))->assertOk()
            ->assertSee('Lihat Penerimaan')->assertDontSee('Buat Retur/Klaim');
        DB::table('role_permissions')->where('role', 'staf_gudang')->where('permission', '!=', 'inventory.stock.view')->delete();
        $hidden = $this->get(route('notifications.index', ['condition'=>'return_pending']))->assertOk();
        $this->assertSame(0, $hidden->viewData('notifications')->total());
    }

    public function test_drafts_and_approvals_reduce_pending_quantity_and_rejected_claims_become_available_again(): void
    {
        extract($this->receivingIssue());
        $return = PurchaseReturn::create(['return_number'=>'RT-NOTIF', 'purchase_receipt_id'=>$receipt->id, 'created_by'=>$owner->id, 'status'=>'draft']);
        foreach (['damaged'=>1, 'wrong'=>1, 'missing'=>3] as $kind=>$quantity) {
            $return->items()->create(['purchase_inspection_id'=>$inspection->id, 'kind'=>$kind, 'quantity'=>$quantity]);
        }
        $read = fn () => collect(app(NotificationService::class)->checkAllProducts())->flatMap(fn ($item) => $item['conditions'])->where('kind', 'return_pending')->values();
        $issues = $read();
        $this->assertCount(1, $issues);
        $this->assertEquals(1, $issues->first()['quantity']);
        $return->update(['status'=>'approved']);
        $return->items()->where('kind', 'damaged')->update(['quantity'=>2]);
        $this->assertCount(0, $read());
        $return->update(['status'=>'rejected']);
        $this->assertCount(3, $read());
        $product->update(['is_active'=>false]);
        $this->assertCount(3, $read());
        $order->update(['status'=>'completed']);
        $this->assertCount(0, $read());
    }

    public function test_multiple_receipts_share_one_product_notification_and_missing_storage_is_explicit(): void
    {
        extract($this->receivingIssue());
        $inspection->update(['quarantine_location_id'=>null]);
        $other = PurchaseReceipt::create(['purchase_order_id'=>$order->id, 'receipt_date'=>today(), 'received_by'=>$owner->id]);
        $other->inspections()->create(['purchase_order_item_id'=>$inspection->purchase_order_item_id, 'quantity_missing'=>4]);
        $response = $this->get(route('notifications.index', ['condition'=>'return_pending']))->assertOk()
            ->assertSee('Lokasi penyimpanan retur belum tercatat.')
            ->assertSee(route('purchase.returns.create', ['receipt_id'=>$other->id]), false);
        $this->assertSame(1, $response->viewData('notifications')->total());
        $this->assertCount(4, collect($response->viewData('notifications')->first()['conditions'])->where('kind', 'return_pending'));
    }
}
