<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseReceiptAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_gudang_can_open_purchase_receipts(): void
    {
        $staffGudang = User::create([
            'name' => 'Staf Gudang',
            'email' => 'gudang@example.com',
            'password' => 'password',
            'role' => 'staf_gudang',
        ]);

        $this->actingAs($staffGudang)
            ->get(route('purchase.receipts.index'))
            ->assertOk();

        $this->actingAs($staffGudang)
            ->get(route('purchase.receipts.create'))
            ->assertOk();
    }

    public function test_cashier_cannot_open_purchase_receipts(): void
    {
        $cashier = User::create([
            'name' => 'Kasir',
            'email' => 'kasir@example.com',
            'password' => 'password',
            'role' => 'kasir',
        ]);

        $this->actingAs($cashier)
            ->get(route('purchase.receipts.index'))
            ->assertForbidden();
    }

    public function test_staff_gudang_cannot_open_purchase_orders(): void
    {
        $staffGudang = User::create([
            'name' => 'Staf Gudang',
            'email' => 'gudang-po@example.com',
            'password' => 'password',
            'role' => 'staf_gudang',
        ]);

        $this->actingAs($staffGudang)
            ->get(route('purchase.orders.index'))
            ->assertForbidden();
    }

    public function test_purchase_receipts_index_shows_receivable_purchase_orders(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'password',
            'role' => 'owner',
        ]);
        $supplier = Supplier::create(['name' => 'Supplier Tes']);
        $unit = Unit::create(['name' => 'Dus']);
        $category = Category::create(['name' => 'Kategori Tes']);
        $product = Product::create([
            'name' => 'Produk Tes',
            'category_id' => $category->id,
            'buy_unit_id' => $unit->id,
            'buy_price' => 10000,
            'sell_price_wholesale' => 12000,
        ]);
        $productUnit = ProductUnit::create([
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'conversion_to_base' => 1,
            'buy_price' => 10000,
            'sell_price' => 12000,
            'is_base_unit' => true,
            'is_default_purchase' => true,
            'is_default_sale' => true,
        ]);

        $receivableOrder = PurchaseOrder::create([
            'po_number' => 'PO-2026-000001',
            'supplier_id' => $supplier->id,
            'order_date' => '2026-08-01',
            'status' => 'partial',
            'created_by' => $owner->id,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $receivableOrder->id,
            'product_id' => $product->id,
            'product_unit_id' => $productUnit->id,
            'quantity_ordered' => 10,
            'quantity_received' => 4,
            'buy_price' => 10000,
        ]);

        $completedOrder = PurchaseOrder::create([
            'po_number' => 'PO-2026-000002',
            'supplier_id' => $supplier->id,
            'order_date' => '2026-08-02',
            'status' => 'completed',
            'created_by' => $owner->id,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $completedOrder->id,
            'product_id' => $product->id,
            'product_unit_id' => $productUnit->id,
            'quantity_ordered' => 5,
            'quantity_received' => 5,
            'buy_price' => 10000,
        ]);

        $this->actingAs($owner)
            ->get(route('purchase.receipts.index'))
            ->assertOk()
            ->assertSee('PO Siap Diterima')
            ->assertSee('PO-2026-000001')
            ->assertSee('Terima')
            ->assertDontSee('PO-2026-000002');
    }
}
