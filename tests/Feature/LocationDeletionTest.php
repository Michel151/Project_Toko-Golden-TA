<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, ProductLocation, PurchaseInspection, PurchaseOrder, PurchaseReceipt, Sale, Stock, StockBatch, StockMovement, StockOpname, StockTransfer, Supplier, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocationDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create([
            'name' => 'Owner', 'email' => 'location-delete@example.com', 'password' => 'password', 'role' => 'owner',
        ]));
        $this->location = Location::create(['name' => 'Gudang Lama']);
        $this->product = Product::create([
            'name' => 'Barang', 'category_id' => Category::create(['name' => 'Kategori'])->id,
            'buy_unit_id' => Unit::create(['name' => 'Botol'])->id, 'buy_price' => 1000, 'sell_price_wholesale' => 1500,
        ]);
        Stock::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'quantity' => 0]);
    }

    public static function historyCases(): array
    {
        return array_map(fn ($kind) => [$kind], [
            'receipt', 'sale', 'transfer_from', 'transfer_to', 'opname', 'movement', 'empty_batch', 'quarantine',
        ]);
    }

    private function addHistory(string $kind): string
    {
        $ids = ['location_id' => $this->location->id, 'product_id' => $this->product->id];
        if (in_array($kind, ['receipt', 'quarantine'], true)) {
            $order = PurchaseOrder::create([
                'po_number' => 'PO-DELETE', 'supplier_id' => Supplier::create(['name' => 'Supplier'])->id,
                'order_date' => today(), 'created_by' => auth()->id(),
            ]);
            $item = $order->items()->create(['product_id' => $this->product->id, 'quantity_ordered' => 1, 'buy_price' => 1000]);
            $receipt = PurchaseReceipt::create(['purchase_order_id' => $order->id, 'receipt_date' => today(), 'received_by' => auth()->id()]);
            if ($kind === 'receipt') {
                $receipt->items()->create($ids + ['purchase_order_item_id' => $item->id, 'quantity_received' => 1]);
                return 'purchase_receipt_items';
            }
            PurchaseInspection::create([
                'purchase_receipt_id' => $receipt->id, 'purchase_order_item_id' => $item->id,
                'quarantine_location_id' => $this->location->id, 'quantity_damaged' => 1,
            ]);
            return 'purchase_inspections';
        }
        if ($kind === 'sale') {
            $sale = Sale::create([
                'sale_number' => 'INV-DELETE', 'sale_date' => today(), 'total_amount' => 1500,
                'amount_paid' => 1500, 'change_amount' => 0, 'created_by' => auth()->id(),
            ]);
            $item = $sale->items()->create([
                'product_id' => $this->product->id, 'sale_type' => 'ecer', 'quantity' => 1, 'unit_price' => 1500, 'subtotal' => 1500,
            ]);
            $item->sources()->create(['location_id' => $this->location->id, 'quantity' => 1, 'base_quantity' => 1]);
            return 'sale_item_sources';
        }
        if (in_array($kind, ['transfer_from', 'transfer_to'], true)) {
            $other = Location::create(['name' => 'Toko']);
            StockTransfer::create([
                'transfer_number' => 'TRF-DELETE', 'product_id' => $this->product->id,
                'from_location_id' => $kind === 'transfer_from' ? $this->location->id : $other->id,
                'to_location_id' => $kind === 'transfer_to' ? $this->location->id : $other->id,
                'quantity' => 1, 'transfer_date' => today(), 'created_by' => auth()->id(),
            ]);
            return 'stock_transfers';
        }
        if ($kind === 'opname') {
            $this->addOpname();
            return 'stock_opnames';
        }
        if ($kind === 'movement') {
            StockMovement::create($ids + [
                'movement_type' => 'sale', 'quantity' => -1, 'balance_before' => 1, 'balance_after' => 0,
                'movement_date' => today(), 'created_by' => auth()->id(),
            ]);
            return 'stock_movements';
        }
        StockBatch::create($ids + ['received_date' => today(), 'quantity_initial' => 1, 'quantity_remaining' => 0]);
        return 'stock_batches';
    }

    private function addOpname(): void
    {
        StockOpname::create([
            'opname_number' => 'OPN-DELETE', 'location_id' => $this->location->id,
            'opname_date' => today(), 'created_by' => auth()->id(),
        ]);
    }

    #[DataProvider('historyCases')]
    public function test_empty_location_with_history_is_not_deleted(string $kind): void
    {
        $table = $this->addHistory($kind);
        $before = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        $this->from(route('master.locations.index'))->delete(route('master.locations.destroy', $this->location))
            ->assertRedirect(route('master.locations.index'))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'riwayat transaksi'));
        $this->assertDatabaseHas('locations', ['id' => $this->location->id]);
        $this->assertDatabaseHas('stocks', ['location_id' => $this->location->id, 'quantity' => 0]);
        $this->assertSame($before, DB::table($table)->get()->map(fn ($row) => (array) $row)->all());
        $this->get(route('master.locations.index'))->assertOk()->assertSee('Lokasi tidak dapat dihapus karena masih digunakan dalam riwayat transaksi.');
    }

    public function test_location_with_stock_keeps_the_existing_clear_stock_message(): void
    {
        Stock::where('location_id', $this->location->id)->update(['quantity' => 1]);
        $this->delete(route('master.locations.destroy', $this->location))
            ->assertSessionHas('error', 'Lokasi tidak bisa dihapus karena masih memiliki stok barang.');
        $this->assertDatabaseHas('locations', ['id' => $this->location->id]);
    }

    public function test_unused_location_with_zero_stock_can_be_deleted(): void
    {
        ProductLocation::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'min_stock' => 0]);
        $this->delete(route('master.locations.destroy', $this->location))
            ->assertRedirect(route('master.locations.index'))->assertSessionHas('success', 'Lokasi berhasil dihapus.');
        $this->assertDatabaseMissing('locations', ['id' => $this->location->id]);
        $this->assertDatabaseMissing('stocks', ['location_id' => $this->location->id]);
        $this->assertDatabaseMissing('product_locations', ['location_id' => $this->location->id]);
    }

    public function test_reference_added_after_the_check_returns_a_clear_error_and_rolls_back(): void
    {
        // Insert at deletion time to exercise the database fallback after the precheck.
        Location::deleting(function () { $this->addOpname(); });
        try {
            $this->delete(route('master.locations.destroy', $this->location))
                ->assertSessionHas('error', fn ($message) => str_contains($message, 'riwayat transaksi'));
        } finally {
            Event::forget('eloquent.deleting: '.Location::class);
        }
        $this->assertDatabaseHas('locations', ['id' => $this->location->id]);
        $this->assertDatabaseHas('stocks', ['location_id' => $this->location->id, 'quantity' => 0]);
        $this->assertDatabaseMissing('stock_opnames', ['opname_number' => 'OPN-DELETE']);
    }
}
