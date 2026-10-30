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
        $this->assertSame(['products'=>1, 'expiry'=>0, 'stock'=>0, 'returns'=>1], $response->viewData('summary'));
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

    public function test_approval_notifications_follow_permission_and_disappear_after_real_workflow_transitions(): void
    {
        extract($this->receivingIssue());
        $makeDraft = function (string $number) use ($receipt, $owner, $inspection) {
            $draft = PurchaseReturn::create(['return_number'=>$number, 'purchase_receipt_id'=>$receipt->id, 'created_by'=>$owner->id, 'status'=>'draft']);
            foreach (['damaged'=>2, 'wrong'=>1, 'missing'=>3] as $kind=>$quantity) {
                $draft->items()->create(['purchase_inspection_id'=>$inspection->id, 'kind'=>$kind, 'quantity'=>$quantity]);
            }
            return $draft;
        };
        $draft = $makeDraft('RT-APPROVAL-1');
        $url = route('notifications.index', ['condition'=>'return_approval']);
        $response = $this->get($url)->assertOk()->assertSee('Tinjau Pengajuan · RT-APPROVAL-1')
            ->assertSee(route('purchase.returns.show', $draft->id), false)
            ->assertSee('Supplier Retur')->assertSee('Area Retur Gudang 1')->assertSee('diajukan oleh Owner');
        $this->assertSame(1, $response->viewData('notifications')->total());
        $this->assertSame(1, $response->viewData('summary')['returns']);
        $this->get(route('notifications.index', ['condition'=>'return_all']))->assertOk()
            ->assertViewHas('summary', ['products'=>1, 'expiry'=>0, 'stock'=>0, 'returns'=>1]);

        $staff = User::create(['name'=>'Staf', 'email'=>'approval-notif@test.com', 'password'=>'password', 'role'=>'staf_gudang']);
        DB::table('role_permissions')->where('role', 'staf_gudang')->delete();
        foreach (['inventory.stock.view', 'purchase.returns.view'] as $permission) {
            DB::table('role_permissions')->insert(['role'=>'staf_gudang', 'permission'=>$permission]);
        }
        $hidden = $this->actingAs($staff)->get($url)->assertOk()->assertDontSee('Tinjau Pengajuan');
        $this->assertSame(0, $hidden->viewData('notifications')->total());
        DB::table('role_permissions')->insert(['role'=>'staf_gudang', 'permission'=>'purchase.returns.approve']);
        $this->get($url)->assertOk()->assertSee('Tinjau Pengajuan · RT-APPROVAL-1');

        $this->actingAs($owner)->post(route('purchase.returns.reject', $draft), ['rejection_reason'=>'Perlu diperiksa ulang'])->assertRedirect();
        $this->assertSame('rejected', $draft->fresh()->status);
        $this->assertSame(0, $this->get($url)->assertOk()->viewData('notifications')->total());
        $this->assertSame(1, $this->get(route('notifications.index', ['condition'=>'return_pending']))->assertOk()->viewData('notifications')->total());
        $newDraft = $makeDraft('RT-APPROVAL-2');
        $this->get($url)->assertOk()->assertSee('Tinjau Pengajuan · RT-APPROVAL-2');
        $this->post(route('purchase.returns.approve', $newDraft))->assertRedirect();
        $this->assertSame('approved', $newDraft->fresh()->status);
        $this->assertNotNull($newDraft->fresh()->replacement_purchase_order_id);
        $this->assertSame(0, $this->get($url)->assertOk()->viewData('notifications')->total());
    }
}
