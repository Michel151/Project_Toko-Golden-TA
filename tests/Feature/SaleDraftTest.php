<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\SaleDraft;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaleDraftTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'owner'): User
    {
        return User::create(['name' => 'Kasir', 'email' => Str::uuid().'@example.com', 'password' => 'password', 'role' => $role]);
    }

    private function data(int $version = 0, string $status = 'active'): array
    {
        return [
            'version' => $version, 'status' => $status, 'client_updated_at' => 123456789,
            'payload' => $status === 'active' ? [
                'sale_date' => today()->toDateString(), 'payment_method' => 'tunai', 'sale_status' => 'paid',
                'customer_name' => 'Pelanggan', 'amount_paid' => 0, 'default_location_id' => null,
                'items' => [['product_id' => null, 'product_name' => 'Belum selesai', 'product_unit_id' => null, 'quantity' => null, 'unit_price' => 0, 'source_mode' => '', 'sources' => []]],
            ] : null,
        ];
    }

    private function saleData(): array
    {
        $category = Category::create(['name' => 'Kategori']);
        $unit = Unit::create(['name' => 'Pcs']);
        $location = Location::create(['name' => 'Toko']);
        $product = Product::create(['name' => 'Barang', 'category_id' => $category->id, 'buy_unit_id' => $unit->id, 'buy_price' => 500, 'sell_price_wholesale' => 1000, 'can_retail' => false, 'conversion_factor' => 1, 'min_stock_total' => 0, 'is_expirable' => false, 'is_active' => true]);
        $productUnit = ProductUnit::create(['product_id' => $product->id, 'unit_id' => $unit->id, 'conversion_to_base' => 1, 'buy_price' => 500, 'sell_price' => 1000, 'is_base_unit' => true, 'is_default_purchase' => true, 'is_default_sale' => true]);
        Stock::create(['product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 10]);

        return ['sale_date' => today()->toDateString(), 'sale_status' => 'paid', 'transaction_confirmed' => 1, 'payment_method' => 'tunai', 'amount_paid' => 2000, 'items' => [['product_id' => $product->id, 'product_unit_id' => $productUnit->id, 'quantity' => 2, 'unit_price' => 1000, 'sources' => [['location_id' => $location->id, 'quantity' => 2]]]]];
    }

    public function test_draft_accepts_incomplete_items_without_creating_sale_or_changing_stock(): void
    {
        $this->actingAs($this->user());
        $this->saleData();
        $id = (string) Str::uuid();
        $this->putJson(route('sale.drafts.update', $id), $this->data())->assertOk()->assertJsonPath('version', 1);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('stocks', ['quantity' => 10]);
        $this->getJson(route('sale.drafts.index'))->assertOk()->assertJsonCount(1);
    }

    public function test_drafts_are_private_and_require_sale_permission(): void
    {
        $this->actingAs($this->user());
        $id = (string) Str::uuid();
        $this->putJson(route('sale.drafts.update', $id), $this->data())->assertOk();
        $this->actingAs($this->user());
        $this->getJson(route('sale.drafts.index'))->assertJsonCount(0);
        $this->putJson(route('sale.drafts.update', $id), $this->data(1))->assertForbidden();
        $this->actingAs($this->user('staf_gudang'));
        $this->getJson(route('sale.drafts.index'))->assertForbidden();
        $this->putJson(route('sale.drafts.update', Str::uuid()), $this->data())->assertForbidden();
    }

    public function test_stale_revision_and_delayed_autosave_cannot_overwrite_or_revive_draft(): void
    {
        $this->actingAs($this->user());
        $id = (string) Str::uuid();
        $this->putJson(route('sale.drafts.update', $id), $this->data())->assertOk();
        $this->putJson(route('sale.drafts.update', $id), $this->data())->assertConflict();
        $this->putJson(route('sale.drafts.update', $id), $this->data(1, 'discarded'))->assertOk()->assertJsonPath('payload', null);
        $this->putJson(route('sale.drafts.update', $id), $this->data(2))->assertConflict();
        $this->assertDatabaseHas('sale_drafts', ['id' => $id, 'status' => 'discarded', 'version' => 2]);
    }

    public function test_payment_confirmation_is_never_saved_in_draft(): void
    {
        $this->actingAs($this->user());
        $data = $this->data();
        $data['payload']['transaction_confirmed'] = true;
        $this->putJson(route('sale.drafts.update', Str::uuid()), $data)->assertUnprocessable();
        $this->assertDatabaseCount('sale_drafts', 0);
    }

    public function test_successful_sale_closes_draft_atomically_and_prevents_duplicate_submission(): void
    {
        $this->actingAs($this->user());
        $data = $this->saleData();
        $id = (string) Str::uuid();
        $this->putJson(route('sale.drafts.update', $id), $this->data())->assertOk();
        $data += ['draft_id' => $id, 'draft_version' => 1];
        $this->post(route('sale.sales.store'), $data)->assertSessionHas('success')->assertSessionHas('completed_sale_draft', $id);
        $this->assertDatabaseHas('sale_drafts', ['id' => $id, 'status' => 'consumed', 'payload' => null]);
        $this->post(route('sale.sales.store'), $data)->assertSessionHas('error');
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('stocks', ['quantity' => 8]);
        $this->putJson(route('sale.drafts.update', $id), $this->data(2))->assertConflict();
    }

    public function test_failed_sale_preserves_draft_and_stock(): void
    {
        $this->actingAs($this->user());
        $data = $this->saleData();
        Stock::query()->update(['quantity' => 0]);
        $id = (string) Str::uuid();
        $this->putJson(route('sale.drafts.update', $id), $this->data())->assertOk();
        $this->post(route('sale.sales.store'), $data + ['draft_id' => $id, 'draft_version' => 1])->assertSessionHas('error');
        $this->assertDatabaseHas('sale_drafts', ['id' => $id, 'status' => 'active', 'version' => 1]);
        $this->assertNotNull(SaleDraft::find($id)->payload);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_sale_cannot_consume_another_users_draft_or_stale_revision(): void
    {
        $owner = $this->user();
        $this->actingAs($owner);
        $id = (string) Str::uuid();
        $this->putJson(route('sale.drafts.update', $id), $this->data())->assertOk();
        $data = $this->saleData();
        $this->actingAs($this->user());
        $this->post(route('sale.sales.store'), $data + ['draft_id' => $id, 'draft_version' => 1])->assertSessionHas('error');
        $this->actingAs($owner);
        $this->post(route('sale.sales.store'), $data + ['draft_id' => $id, 'draft_version' => 0])->assertSessionHas('error');
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('stocks', ['quantity' => 10]);
    }

    public function test_edit_draft_has_separate_context_and_cannot_be_consumed_by_new_sale(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $sale = Sale::create(['sale_number' => 'DRAFT-CONTEXT', 'sale_date' => today(), 'total_amount' => 0, 'payment_method' => 'tunai', 'amount_paid' => 0, 'change_amount' => 0, 'status' => 'pending', 'hold_until' => now('Asia/Jakarta')->addHour(), 'created_by' => $user->id]);
        $id = (string) Str::uuid();
        $this->putJson(route('sale.drafts.update', $id), $this->data() + ['sale_id' => $sale->id])->assertOk();
        $this->getJson(route('sale.drafts.index'))->assertJsonCount(0);
        $this->getJson(route('sale.drafts.index', ['sale_id' => $sale->id]))->assertJsonCount(1);
        $this->post(route('sale.sales.store'), $this->saleData() + ['draft_id' => $id, 'draft_version' => 1])->assertSessionHas('error');
        $this->assertDatabaseHas('sale_drafts', ['id' => $id, 'status' => 'active']);
        $this->actingAs($this->user('kasir'));
        $this->getJson(route('sale.drafts.index', ['sale_id' => $sale->id]))->assertForbidden();
    }

    public function test_pending_sale_update_closes_edit_draft_and_recalculates_stock_once(): void
    {
        $this->travelTo(now('Asia/Jakarta')->setTime(15, 0));
        $this->actingAs($this->user());
        $data = $this->saleData();
        $data['sale_status'] = 'pending';
        $data['amount_paid'] = 0;
        $this->post(route('sale.sales.store'), $data)->assertSessionHas('success');
        $sale = Sale::firstOrFail();
        $id = (string) Str::uuid();
        $this->putJson(route('sale.drafts.update', $id), $this->data() + ['sale_id' => $sale->id])->assertOk();
        $data['items'][0]['quantity'] = 3;
        $data['items'][0]['sources'][0]['quantity'] = 3;
        $data += ['draft_id' => $id, 'draft_version' => 1];
        $this->put(route('sale.sales.update', $sale), $data)->assertSessionHas('success')->assertSessionHas('completed_sale_draft', $id);
        $this->assertDatabaseHas('sale_drafts', ['id' => $id, 'status' => 'consumed']);
        $this->assertDatabaseHas('stocks', ['quantity' => 7]);
        $this->put(route('sale.sales.update', $sale), $data)->assertSessionHas('error');
        $this->assertDatabaseHas('stocks', ['quantity' => 7]);
        $this->assertEquals(3, $sale->fresh()->items()->first()->quantity);
    }
}
