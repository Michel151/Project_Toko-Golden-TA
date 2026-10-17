<?php

namespace Tests\Feature;

use App\Models\{PurchaseOrder, Supplier, User};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderNumberFormatTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $number, ?int $rootId = null): PurchaseOrder
    {
        $user=User::firstOrCreate(['email'=>'po-number@example.com'],[
            'name'=>'Owner', 'password'=>'password', 'role'=>'owner',
        ]);
        $supplier=Supplier::firstOrCreate(['name'=>'Supplier Uji Nomor PO']);
        return PurchaseOrder::create([
            'po_number'=>$number, 'supplier_id'=>$supplier->id, 'created_by'=>$user->id,
            'order_date'=>'2026-10-17', 'status'=>'pending', 'root_purchase_order_id'=>$rootId,
        ]);
    }

    public function test_numbers_increment_within_the_month_and_restart_each_month_and_year(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 10:00:00','Asia/Jakarta'));
        $this->assertSame('PO/2026/10/000001',PurchaseOrder::generatePoNumber());
        $this->order(PurchaseOrder::generatePoNumber());
        $this->assertSame('PO/2026/10/000002',PurchaseOrder::generatePoNumber());
        $november=Carbon::parse('2026-11-01','Asia/Jakarta');
        $this->assertSame('PO/2026/11/000001',PurchaseOrder::generatePoNumber($november));
        $this->order('PO/2026/11/000001');
        $this->assertSame('PO/2026/11/000002',PurchaseOrder::generatePoNumber($november));
        $this->assertSame('PO/2026/10/000002',PurchaseOrder::generatePoNumber());
        $this->assertSame('PO/2027/01/000001',PurchaseOrder::generatePoNumber(Carbon::parse('2027-01-01','Asia/Jakarta')));
    }

    public function test_legacy_and_return_orders_do_not_increment_the_normal_monthly_sequence(): void
    {
        $legacy=$this->order('PO-2026-000999');
        $this->order('PO/R-2026-000999',$legacy->id);
        $root=$this->order('PO/2026/09/000999');
        $this->order('PO/R/2026/10/000999',$root->id);
        $this->order('PO/2025/10/000999');
        $this->assertSame('PO/2026/10/000001',PurchaseOrder::generatePoNumber(Carbon::parse('2026-10-17','Asia/Jakarta')));
        $this->assertSame('PO-2026-000999',$legacy->fresh()->po_number);
    }

    public function test_highest_monthly_number_is_used_regardless_of_insertion_order(): void
    {
        $this->order('PO/2026/10/000100');
        $this->order('PO/2026/10/000003');
        $this->assertSame('PO/2026/10/000101',PurchaseOrder::generatePoNumber(Carbon::parse('2026-10-17','Asia/Jakarta')));
    }

    public function test_issue_time_is_converted_to_jakarta_without_mutating_it(): void
    {
        $issuedAt=Carbon::parse('2026-12-31 17:01:00','UTC');
        $this->assertSame('PO/2027/01/000001',PurchaseOrder::generatePoNumber($issuedAt));
        $this->assertSame('2026-12-31 17:01:00 UTC',$issuedAt->format('Y-m-d H:i:s T'));
    }
}
