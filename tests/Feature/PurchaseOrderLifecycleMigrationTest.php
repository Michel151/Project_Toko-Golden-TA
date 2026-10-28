<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, ProductUnit, PurchaseOrder, Supplier, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseOrderLifecycleMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh', ['--force'=>true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$inMemoryConnections = [];
        parent::tearDown();
    }

    public function test_upgrade_preserves_populated_legacy_orders_receipts_stocks_and_hpp(): void
    {
        $owner = User::create(['name'=>'Owner Legacy', 'email'=>'legacy-po@test.com', 'password'=>'password', 'role'=>'owner']);
        $this->actingAs($owner);
        $category = Category::create(['name'=>'Legacy']);
        $base = Unit::create(['name'=>'Botol']);
        $supplier = Supplier::create(['name'=>'Supplier Legacy']);
        $product = Product::create(['name'=>'Barang Legacy', 'category_id'=>$category->id, 'buy_unit_id'=>$base->id, 'buy_price'=>50000, 'sell_price_wholesale'=>60000]);
        $unit = ProductUnit::create(['product_id'=>$product->id, 'unit_id'=>$base->id, 'conversion_to_base'=>1, 'buy_price'=>50000, 'sell_price'=>60000, 'is_base_unit'=>true, 'is_default_purchase'=>true]);
        $location = Location::create(['name'=>'Toko']);
        DB::table('inventory_cost_settings')->where('id',1)->update(['automatic_from_zero'=>true]);
        $migration = require database_path('migrations/2026_10_28_000002_add_purchase_order_lifecycle.php');
        $migration->down();
        foreach (['pending', 'partial', 'completed'] as $index=>$status) {
            $order = PurchaseOrder::create(['po_number'=>'PO-LEGACY-'.$index, 'supplier_id'=>$supplier->id, 'order_date'=>'2026-10-28', 'status'=>'pending', 'created_by'=>$owner->id]);
            $item = $order->items()->create(['product_id'=>$product->id, 'product_unit_id'=>$unit->id, 'quantity_ordered'=>10, 'buy_price'=>50000]);
            if ($status !== 'pending') {
                $quantity = $status === 'partial' ? 4 : 10;
                $this->post(route('purchase.receipts.store'), ['purchase_order_id'=>$order->id, 'receipt_date'=>'2026-10-28', 'items'=>[
                    ['purchase_order_item_id'=>$item->id, 'product_id'=>$product->id, 'product_unit_id'=>$unit->id, 'quantity_received'=>$quantity, 'allocations'=>[['quantity'=>$quantity, 'location_id'=>$location->id]]],
                ]])->assertSessionHasNoErrors();
            }
        }
        $tables = ['purchase_orders', 'purchase_order_items', 'purchase_receipts', 'purchase_receipt_items', 'stocks', 'stock_batches', 'stock_movements', 'inventory_cost_balances', 'inventory_cost_entries'];
        $before = collect($tables)->mapWithKeys(fn ($table)=>[$table=>DB::table($table)->get()->map(fn ($row)=>(array) $row)->all()])->all();
        $migration->up();
        foreach ($before as $table=>$rows) {
            $actual = DB::table($table)->get()->map(fn ($row)=>array_intersect_key((array) $row, $rows ? $rows[0] : []))->all();
            $this->assertSame($rows, $actual, $table.' must remain unchanged');
        }
        $this->assertSame(['pending', 'partial', 'completed'], PurchaseOrder::orderBy('id')->pluck('status')->all());
        $this->assertSame(0, PurchaseOrder::whereNotNull('confirmed_at')->count());
        $this->assertSame(0, PurchaseOrder::where('status','draft')->count());
        $this->get(route('purchase.orders.index'))->assertOk();
        foreach (PurchaseOrder::all() as $order) $this->get(route('purchase.orders.show', $order))->assertOk();
    }
}
