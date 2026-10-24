<?php

namespace Tests\Feature;

use App\Models\{AuditLog, Category, InventoryCostBalance, Location, Product, PurchaseOrder, PurchaseReceipt, Sale, Stock, StockBatch, StockMovement, StockOpname, StockTransfer, Supplier, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MasterDataDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $cashier;
    private Product $product;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner-delete@example.com', 'password' => 'password', 'role' => 'owner']);
        $this->actingAs($this->owner);
        $this->cashier = User::create(['name' => 'Kasir Lama', 'email' => 'cashier-delete@example.com', 'password' => 'password', 'role' => 'kasir']);
        $this->product = Product::create([
            'name' => 'Barang Lama', 'category_id' => Category::create(['name' => 'Kategori'])->id,
            'buy_unit_id' => Unit::create(['name' => 'Botol'])->id, 'buy_price' => 1000, 'sell_price_wholesale' => 1500,
        ]);
        $this->location = Location::create(['name' => 'Toko']);
        Stock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 0]);
    }

    private function sale(): Sale
    {
        return Sale::create(['sale_number' => 'INV-DELETE', 'sale_date' => today(), 'total_amount' => 1500, 'amount_paid' => 1500, 'created_by' => $this->cashier->id]);
    }

    private function receipt(): PurchaseReceipt
    {
        $order = PurchaseOrder::create([
            'po_number' => 'PO-DELETE', 'supplier_id' => Supplier::create(['name' => 'Supplier'])->id,
            'order_date' => today(), 'created_by' => $this->cashier->id,
        ]);
        $order->items()->create(['product_id' => $this->product->id, 'quantity_ordered' => 1, 'buy_price' => 1000]);
        return PurchaseReceipt::create(['purchase_order_id' => $order->id, 'receipt_date' => today(), 'received_by' => $this->cashier->id]);
    }

    private function opname(): void
    {
        $opname = StockOpname::create(['opname_number' => 'OPN-DELETE', 'location_id' => $this->location->id, 'opname_date' => today(), 'created_by' => $this->cashier->id]);
        $opname->items()->create(['product_id' => $this->product->id, 'system_quantity' => 0, 'physical_quantity' => 0, 'difference' => 0]);
    }

    public static function productHistoryCases(): array
    {
        return array_map(fn ($kind) => [$kind], ['sale', 'purchase', 'receipt', 'legacy_transfer', 'transfer_item', 'opname', 'batch', 'movement', 'cost']);
    }

    #[DataProvider('productHistoryCases')]
    public function test_zero_stock_product_with_history_cannot_be_deleted(string $kind): void
    {
        $ids = ['product_id' => $this->product->id, 'location_id' => $this->location->id];
        switch ($kind) {
            case 'sale':
                $this->sale()->items()->create(['product_id' => $this->product->id, 'sale_type' => 'ecer', 'quantity' => 1, 'unit_price' => 1500, 'subtotal' => 1500]);
                break;
            case 'purchase':
            case 'receipt':
                $receipt = $this->receipt();
                if ($kind === 'receipt') $receipt->items()->create($ids + ['purchase_order_item_id' => $receipt->purchaseOrder->items()->firstOrFail()->id, 'quantity_received' => 1]);
                break;
            case 'legacy_transfer':
            case 'transfer_item':
                $other = Location::create(['name' => 'Gudang']);
                $transfer = StockTransfer::create([
                    'transfer_number' => 'TRF-DELETE', 'product_id' => $this->product->id,
                    'from_location_id' => $this->location->id, 'to_location_id' => $other->id,
                    'quantity' => 1, 'transfer_date' => today(), 'created_by' => $this->cashier->id,
                ]);
                if ($kind === 'transfer_item') {
                    $transfer->items()->create(['product_id' => $this->product->id, 'quantity' => 1]);
                    // The item must protect this product even when the legacy column points elsewhere.
                    $another = $this->product->replicate(); $another->name = 'Barang Lain'; $another->save();
                    $transfer->update(['product_id' => $another->id]);
                }
                break;
            case 'opname': $this->opname(); break;
            case 'batch': StockBatch::create($ids + ['received_date' => today(), 'quantity_initial' => 1, 'quantity_remaining' => 0]); break;
            case 'movement':
                StockMovement::create($ids + ['movement_type' => 'sale', 'quantity' => -1, 'balance_before' => 1, 'balance_after' => 0, 'movement_date' => today()]);
                break;
            case 'cost': InventoryCostBalance::create(['product_id' => $this->product->id, 'quantity' => 0, 'inventory_value' => 0, 'average_cost' => 0]); break;
        }
        $this->from(route('master.products.index'))->delete(route('master.products.destroy', $this->product))
            ->assertRedirect(route('master.products.index'))->assertSessionHas('error', fn ($message) => str_contains($message, 'riwayat'));
        $this->assertDatabaseHas('products', ['id' => $this->product->id]);
        $this->assertDatabaseHas('stocks', $ids + ['quantity' => 0]);
    }

    public static function userHistoryCases(): array
    {
        return array_map(fn ($kind) => [$kind], ['sale', 'receipt', 'opname', 'expense_confirmation', 'tax_revision', 'audit', 'costing', 'import', 'return_approval', 'return_dispatch']);
    }

    #[DataProvider('userHistoryCases')]
    public function test_user_with_history_cannot_be_deleted(string $kind): void
    {
        switch ($kind) {
            case 'sale': $this->sale(); break;
            case 'receipt': $this->receipt(); break;
            case 'opname': $this->opname(); break;
            case 'expense_confirmation':
                DB::table('expenses')->insert(['expense_number' => 'EXP-DELETE', 'submission_token' => (string) str()->uuid(), 'recognized_date' => today(), 'category' => 'other', 'description' => 'Beban', 'amount' => 100, 'created_by' => $this->owner->id, 'confirmed_by' => $this->cashier->id]);
                break;
            case 'tax_revision':
                $tax = DB::table('monthly_taxes')->insertGetId(['month' => '2026-10', 'amount' => 100, 'description' => 'Pajak', 'created_by' => $this->owner->id]);
                DB::table('monthly_tax_revisions')->insert(['monthly_tax_id' => $tax, 'snapshot' => '{}', 'action' => 'update', 'created_by' => $this->cashier->id, 'created_at' => now()]);
                break;
            case 'audit': AuditLog::create(['user_id' => $this->cashier->id, 'action' => 'login', 'module' => 'Autentikasi', 'description' => 'Masuk']); break;
            case 'costing': DB::table('inventory_cost_settings')->where('id', 1)->update(['enabled_by' => $this->cashier->id]); break;
            case 'import': DB::table('product_import_runs')->insert(['token' => (string) str()->uuid(), 'user_id' => $this->cashier->id, 'expires_at' => now()->addHour()]); break;
            case 'return_approval':
            case 'return_dispatch':
                $receipt = $this->receipt();
                // Isolate approval/dispatch from the creator references.
                $receipt->update(['received_by' => $this->owner->id]);
                $receipt->purchaseOrder->update(['created_by' => $this->owner->id]);
                DB::table('purchase_returns')->insert([
                    'return_number' => 'RET-DELETE', 'purchase_receipt_id' => $receipt->id, 'created_by' => $this->owner->id,
                    $kind === 'return_approval' ? 'approved_by' : 'dispatched_by' => $this->cashier->id,
                ]);
                break;
        }
        $this->from(route('master.users.index'))->delete(route('master.users.destroy', $this->cashier))
            ->assertRedirect(route('master.users.index'))->assertSessionHas('error', fn ($message) => str_contains($message, 'riwayat transaksi atau aktivitas'));
        $this->assertDatabaseHas('users', ['id' => $this->cashier->id]);
    }

    public function test_unused_user_and_product_can_be_deleted(): void
    {
        $this->delete(route('master.users.destroy', $this->cashier))->assertSessionHas('success');
        $this->assertDatabaseMissing('users', ['id' => $this->cashier->id]);
        $this->delete(route('master.products.destroy', $this->product))->assertSessionHas('success');
        $this->assertDatabaseMissing('products', ['id' => $this->product->id]);
        $this->assertDatabaseMissing('stocks', ['product_id' => $this->product->id]);
    }

    public function test_self_deletion_and_nonzero_stock_still_have_clear_errors(): void
    {
        $this->delete(route('master.users.destroy', $this->owner))->assertSessionHas('error', 'Anda tidak bisa menghapus akun sendiri.');
        Stock::where('product_id', $this->product->id)->update(['quantity' => 1]);
        $this->delete(route('master.products.destroy', $this->product))->assertSessionHas('error', 'Barang tidak bisa dihapus karena masih memiliki stok.');
        $this->assertDatabaseHas('products', ['id' => $this->product->id]);
    }

    public function test_concurrent_product_reference_is_rejected_without_server_error(): void
    {
        Product::deleting(function () { $this->opname(); });
        try {
            $this->delete(route('master.products.destroy', $this->product))->assertSessionHas('error', fn ($message) => str_contains($message, 'riwayat transaksi'));
        } finally { Event::forget('eloquent.deleting: '.Product::class); }
        $this->assertDatabaseHas('products', ['id' => $this->product->id]);
        $this->assertDatabaseCount('stock_opnames', 0);
    }

    public function test_concurrent_user_reference_is_rejected_without_server_error(): void
    {
        User::deleting(function () { $this->sale(); });
        try {
            $this->delete(route('master.users.destroy', $this->cashier))->assertSessionHas('error', fn ($message) => str_contains($message, 'riwayat transaksi'));
        } finally { Event::forget('eloquent.deleting: '.User::class); }
        $this->assertDatabaseHas('users', ['id' => $this->cashier->id]);
        $this->assertDatabaseCount('sales', 0);
    }
}
