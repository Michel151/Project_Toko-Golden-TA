<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, PurchaseOrder, Sale, Stock, StockBatch, StockMovement, StockOpname, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductExpirySettingTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private Location $location;
    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create([
            'name' => 'Owner', 'email' => 'expiry-setting@example.com', 'password' => 'password', 'role' => 'owner',
        ]));
        $unit = Unit::create(['name' => 'Botol']);
        $category = Category::create(['name' => 'Minuman']);
        $this->location = Location::create(['name' => 'Toko']);
        $this->payload = [
            'name' => 'Minuman', 'category_id' => $category->id, 'is_active' => 1, 'is_expirable' => 0,
            'base_unit_index' => 0, 'default_purchase_unit_index' => 0, 'default_sale_unit_index' => 0,
            'active_locations' => [$this->location->id],
            'product_units' => [[
                'unit_id' => $unit->id, 'contains_quantity' => 1, 'contains_unit_index' => '',
                'buy_price' => 1000, 'sell_price' => 1500,
            ]],
        ];
        $this->post(route('master.products.store'), $this->payload)->assertSessionHasNoErrors();
        $this->product = Product::firstOrFail();
    }

    public static function historyCases(): array
    {
        $cases = [];
        foreach (['stock', 'empty_batch', 'movement', 'pending_sale', 'purchase_order', 'opname'] as $history) {
            foreach ([false, true] as $tracked) {
                $cases[$history.($tracked ? '_disable' : '_enable')] = [$history, $tracked];
            }
        }
        return $cases;
    }

    private function addHistory(string $history): void
    {
        $ids = ['product_id' => $this->product->id, 'location_id' => $this->location->id];
        switch ($history) {
            case 'stock':
                Stock::where($ids)->update(['quantity' => 20]);
                break;
            case 'empty_batch':
                StockBatch::create($ids + [
                    'received_date' => today(), 'expired_date' => today()->subDay(),
                    'quantity_initial' => 20, 'quantity_remaining' => 0,
                ]);
                break;
            case 'movement':
                StockMovement::create($ids + [
                    'movement_type' => 'sale', 'quantity' => -20, 'balance_before' => 20,
                    'balance_after' => 0, 'movement_date' => today(), 'created_by' => auth()->id(),
                ]);
                break;
            case 'pending_sale':
                $sale = Sale::create([
                    'sale_number' => 'INV-EXPIRY', 'sale_date' => today(), 'status' => 'pending',
                    'payment_method' => 'tunai', 'total_amount' => 1500, 'amount_paid' => 0,
                    'change_amount' => 0, 'hold_until' => now()->addHour(), 'created_by' => auth()->id(),
                ]);
                $item = $sale->items()->create([
                    'product_id' => $this->product->id, 'product_unit_id' => $this->product->productUnits()->firstOrFail()->id,
                    'sale_type' => 'ecer', 'quantity' => 1, 'unit_price' => 1500, 'subtotal' => 1500,
                ]);
                $item->sources()->create(['location_id' => $this->location->id, 'quantity' => 1, 'base_quantity' => 1]);
                break;
            case 'purchase_order':
                $supplier = \App\Models\Supplier::create(['name' => 'Supplier']);
                $order = PurchaseOrder::create([
                    'po_number' => 'PO-EXPIRY', 'supplier_id' => $supplier->id, 'order_date' => today(),
                    'status' => 'pending', 'created_by' => auth()->id(),
                ]);
                $order->items()->create([
                    'product_id' => $this->product->id, 'product_unit_id' => $this->product->productUnits()->firstOrFail()->id,
                    'quantity_ordered' => 1, 'buy_price' => 1000,
                ]);
                break;
            case 'opname':
                $opname = StockOpname::create([
                    'opname_number' => 'OPN-EXPIRY', 'location_id' => $this->location->id,
                    'opname_date' => today(), 'status' => 'draft', 'created_by' => auth()->id(),
                ]);
                $opname->items()->create([
                    'product_id' => $this->product->id, 'system_quantity' => 0, 'physical_quantity' => 0, 'difference' => 0,
                ]);
                break;
        }
    }

    #[DataProvider('historyCases')]
    public function test_history_blocks_both_directions_on_server_and_locks_form(string $history, bool $tracked): void
    {
        $this->product->update(['is_expirable' => $tracked]);
        $this->addHistory($history);
        $before = $this->product->fresh()->getAttributes();
        $this->put(route('master.products.update', $this->product), array_replace($this->payload, [
            'is_expirable' => (int) !$tracked, 'name' => 'Must not be saved',
        ]))->assertSessionHasErrors('is_expirable');
        $this->assertSame($before, $this->product->fresh()->getAttributes());

        // Rejected old input must not change the disabled switch or submitted hidden value.
        $response = $this->get(route('master.products.edit', $this->product))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $switch = $xpath->query('//input[@id="isExpirable"]')->item(0);
        $this->assertTrue($switch->hasAttribute('disabled'));
        $this->assertSame($tracked, $switch->hasAttribute('checked'));
        $this->assertSame((string) (int) $tracked, $xpath->query('//input[@type="hidden" and @name="is_expirable"]')->item(0)->getAttribute('value'));
    }

    public function test_unused_product_with_zero_stock_can_change_both_directions(): void
    {
        $response = $this->get(route('master.products.edit', $this->product))->assertOk();
        $this->assertFalse($response->viewData('expirySettingLocked'));
        foreach ([1, 0] as $tracked) {
            $this->put(route('master.products.update', $this->product), array_replace($this->payload, [
                'is_expirable' => $tracked,
            ]))->assertSessionHasNoErrors()->assertRedirect(route('master.products.index'));
            $this->assertSame((bool) $tracked, $this->product->fresh()->is_expirable);
        }
    }

    public function test_locked_product_can_still_update_other_fields_and_preserve_enabled_setting(): void
    {
        $this->product->update(['is_expirable' => true]);
        $this->addHistory('empty_batch');
        $this->put(route('master.products.update', $this->product), array_replace($this->payload, [
            'is_expirable' => 1, 'name' => 'Nama Baru',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('Nama Baru', $this->product->fresh()->name);
        $this->assertTrue($this->product->fresh()->is_expirable);
    }

    public function test_old_edit_form_cannot_change_setting_after_stock_arrives_at_another_location(): void
    {
        $response = $this->get(route('master.products.edit', $this->product))->assertOk();
        $this->assertFalse($response->viewData('expirySettingLocked'));
        $warehouse = Location::create(['name' => 'Gudang']);
        Stock::create(['product_id' => $this->product->id, 'location_id' => $warehouse->id, 'quantity' => 5]);
        $this->put(route('master.products.update', $this->product), array_replace($this->payload, [
            'is_expirable' => 1,
        ]))->assertSessionHasErrors('is_expirable');
        $this->assertFalse($this->product->fresh()->is_expirable);
    }
}
