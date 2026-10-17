<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class HeldSaleTest extends TestCase
{
    use RefreshDatabase;

    private function setupSale(bool $expirable = true): array
    {
        $user = User::create(['name' => 'Kasir', 'email' => uniqid().'@example.com', 'password' => 'password', 'role' => 'kasir']);
        $this->actingAs($user);
        $category = Category::create(['name' => 'Kategori']);
        $unit = Unit::create(['name' => 'Pcs']);
        $location = Location::create(['name' => 'Toko']);
        $product = Product::create([
            'name' => 'Barang', 'category_id' => $category->id, 'buy_unit_id' => $unit->id,
            'buy_price' => 700, 'sell_price_wholesale' => 1000, 'can_retail' => false,
            'conversion_factor' => 1, 'min_stock_total' => 0, 'is_expirable' => $expirable,
            'is_active' => true,
        ]);
        $productUnit = ProductUnit::create([
            'product_id' => $product->id, 'unit_id' => $unit->id, 'conversion_to_base' => 1,
            'buy_price' => 700, 'sell_price' => 1000, 'is_base_unit' => true,
            'is_default_purchase' => true, 'is_default_sale' => true,
        ]);
        $stock = Stock::create(['product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 10]);
        $batch = $expirable ? StockBatch::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'received_date' => '2026-09-01', 'expired_date' => '2026-12-31',
            'quantity_initial' => 10, 'quantity_remaining' => 10,
        ]) : null;

        return [$product, $productUnit, $location, $stock, $batch];
    }

    private function hold(Product $product, ProductUnit $unit, Location $location): Sale
    {
        $this->post(route('sale.sales.store'), [
            'sale_status' => 'pending', 'sale_date' => '2026-09-29',
            'transaction_confirmed' => 1,
            'payment_method' => 'tunai', 'amount_paid' => '0', 'customer_name' => 'Pelanggan',
            'items' => [[
                'product_id' => $product->id, 'product_unit_id' => $unit->id,
                'quantity' => 2, 'unit_price' => '1000',
                'sources' => [['location_id' => $location->id, 'quantity' => 2]],
            ]],
        ])->assertRedirect(route('sale.sales.index'));

        return Sale::firstOrFail();
    }

    public function test_pending_sale_reserves_stock_and_can_be_paid(): void
    {
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 9, 29)->setTime(16, 0));
        [$product, $unit, $location, $stock, $batch] = $this->setupSale();
        $this->get(route('sale.sales.create'))
            ->assertOk()
            ->assertSee('Menunggu Pembayaran')
            ->assertSee('id="transactionConfirmed"', false)
            ->assertSee('id="saveSaleButton" disabled', false);
        $sale = $this->hold($product, $unit, $location);

        $this->assertSame('pending', $sale->status);
        $this->assertSame('INV/2026/09/000001', $sale->sale_number);
        $this->assertSame('16:00:00', $sale->sale_time);
        $this->assertSame('18:00', $sale->hold_until->format('H:i'));
        $this->assertEquals(8, $stock->fresh()->quantity);
        $this->assertEquals(8, $batch->fresh()->quantity_remaining);
        $this->get(route('sale.sales.index'))->assertOk()->assertSee('Menunggu Pembayaran');
        $this->get(route('sale.sales.show', $sale))->assertOk()->assertSee('Selesaikan Pembayaran');

        $this->travel(15)->minutes();
        $this->post(route('sale.sales.complete', $sale), ['payment_method' => 'tunai', 'amount_paid' => '2.000', 'payment_confirmed' => 1])
            ->assertRedirect(route('sale.sales.show', $sale));
        $this->assertSame('paid', $sale->fresh()->status);
        $this->assertSame('INV/2026/09/000001', $sale->fresh()->sale_number);
        $this->assertSame('16:00:00', $sale->fresh()->sale_time);
        $this->assertEquals(8, $stock->fresh()->quantity);
    }

    public function test_overdue_sale_is_cancelled_once_and_restores_its_batch(): void
    {
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 9, 29)->setTime(17, 59));
        [$product, $unit, $location, $stock, $batch] = $this->setupSale();
        $sale = $this->hold($product, $unit, $location);

        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 9, 29)->setTime(18, 0));
        Artisan::call('sales:cancel-overdue');
        Artisan::call('sales:cancel-overdue');

        $this->assertSame('cancelled_auto', $sale->fresh()->status);
        $this->assertEquals(10, $stock->fresh()->quantity);
        $this->assertEquals(10, $batch->fresh()->quantity_remaining);
        $this->assertSame(1, StockMovement::where('movement_type', 'sale_cancelled')->count());
        $this->post(route('sale.sales.complete', $sale), ['payment_method' => 'tunai', 'amount_paid' => 2000, 'payment_confirmed' => 1]);
        $this->assertSame('cancelled_auto', $sale->fresh()->status);
    }

    public function test_manual_cancel_restores_non_expirable_stock(): void
    {
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 9, 29)->setTime(16, 0));
        [$product, $unit, $location, $stock] = $this->setupSale(false);
        $sale = $this->hold($product, $unit, $location);

        $this->post(route('sale.sales.cancel', $sale))->assertRedirect(route('sale.sales.show', $sale));
        $this->assertSame('cancelled', $sale->fresh()->status);
        $this->assertEquals(10, $stock->fresh()->quantity);
    }

    public function test_pending_sale_cannot_be_created_after_six_pm(): void
    {
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 9, 29)->setTime(18, 0));
        [$product, $unit, $location, $stock] = $this->setupSale(false);

        $this->post(route('sale.sales.store'), [
            'sale_status' => 'pending', 'sale_date' => '2026-09-29',
            'transaction_confirmed' => 1,
            'payment_method' => 'tunai', 'amount_paid' => 0,
            'items' => [[
                'product_id' => $product->id, 'product_unit_id' => $unit->id,
                'quantity' => 2, 'unit_price' => 1000,
                'sources' => [['location_id' => $location->id, 'quantity' => 2]],
            ]],
        ])->assertSessionHas('error');

        $this->assertSame(0, Sale::count());
        $this->assertEquals(10, $stock->fresh()->quantity);
    }

    public function test_sale_cannot_be_saved_without_explicit_confirmation(): void
    {
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 9, 29)->setTime(16, 0));
        [$product, $unit, $location, $stock] = $this->setupSale(false);

        $this->post(route('sale.sales.store'), [
            'sale_status' => 'paid', 'sale_date' => '2026-09-29',
            'payment_method' => 'tunai', 'amount_paid' => 2000,
            'items' => [[
                'product_id' => $product->id, 'product_unit_id' => $unit->id,
                'quantity' => 2, 'unit_price' => 1000,
                'sources' => [['location_id' => $location->id, 'quantity' => 2]],
            ]],
        ])->assertSessionHasErrors('transaction_confirmed');

        $this->assertSame(0, Sale::count());
        $this->assertEquals(10, $stock->fresh()->quantity);
    }

    public function test_late_payment_cancels_sale_even_if_scheduler_has_not_run(): void
    {
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 9, 29)->setTime(17, 59));
        [$product, $unit, $location, $stock] = $this->setupSale(false);
        $sale = $this->hold($product, $unit, $location);

        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 9, 29)->setTime(18, 1));
        $this->post(route('sale.sales.complete', $sale), ['payment_method' => 'tunai', 'amount_paid' => 2000, 'payment_confirmed' => 1])
            ->assertSessionHas('error');

        $this->assertSame('cancelled_auto', $sale->fresh()->status);
        $this->assertEquals(10, $stock->fresh()->quantity);
    }

    public function test_pending_sale_can_be_edited_and_stock_is_recalculated(): void
    {
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 9, 29)->setTime(16, 0));
        [$product, $unit, $location, $stock, $batch] = $this->setupSale();
        $sale = $this->hold($product, $unit, $location);

        $this->get(route('sale.sales.edit', $sale))
            ->assertOk()
            ->assertSee('Simpan Perubahan')
            ->assertSee('Pelanggan');

        $this->travel(10)->minutes();
        $this->put(route('sale.sales.update', $sale), [
            'sale_status' => 'pending', 'sale_date' => '2026-09-29',
            'sale_time' => '23:59:59',
            'transaction_confirmed' => 1,
            'payment_method' => 'transfer', 'amount_paid' => 0, 'customer_name' => 'Pelanggan Edit',
            'items' => [[
                'product_id' => $product->id, 'product_unit_id' => $unit->id,
                'quantity' => 4, 'unit_price' => 1200,
                'sources' => [['location_id' => $location->id, 'quantity' => 4]],
            ]],
        ])->assertRedirect(route('sale.sales.show', $sale));

        $sale->refresh();
        $this->assertSame('pending', $sale->status);
        $this->assertSame('Pelanggan Edit', $sale->customer_name);
        $this->assertSame('16:00:00', $sale->sale_time);
        $this->assertSame('transfer', $sale->payment_method);
        $this->assertEquals(4800, $sale->total_amount);
        $this->assertEquals(4, $sale->items()->first()->quantity);
        $this->assertEquals(6, $stock->fresh()->quantity);
        $this->assertEquals(6, $batch->fresh()->quantity_remaining);
    }

    public function test_paid_sale_records_server_time_in_jakarta_and_ignores_submitted_time(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-09-29 09:12:34', 'UTC'));
        [$product, $unit, $location] = $this->setupSale(false);
        $this->post(route('sale.sales.store'), [
            'sale_status'=>'paid', 'sale_date'=>'2026-09-28', 'sale_time'=>'00:00:00',
            'transaction_confirmed'=>1, 'payment_method'=>'tunai', 'amount_paid'=>2000,
            'items'=>[['product_id'=>$product->id,'product_unit_id'=>$unit->id,'quantity'=>2,'unit_price'=>1000,
                'sources'=>[['location_id'=>$location->id,'quantity'=>2]]]],
        ])->assertRedirect(route('sale.sales.index'));
        $sale=Sale::firstOrFail();
        $this->assertSame('16:12:34',$sale->sale_time);
        $this->assertSame('2026-09-28',$sale->sale_date->toDateString());
        $this->assertSame('paid',$sale->status);
    }

    public function test_payment_requires_amount_and_explicit_confirmation(): void
    {
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 9, 29)->setTime(16, 0));
        [$product, $unit, $location, $stock] = $this->setupSale(false);
        $sale = $this->hold($product, $unit, $location);

        $this->get(route('sale.sales.show', $sale))
            ->assertOk()
            ->assertSee('id="completeAmountPaid"', false)
            ->assertSee('value=""', false)
            ->assertSee('id="completePaymentButton" disabled', false);

        $this->post(route('sale.sales.complete', $sale), [
            'payment_method' => 'tunai', 'amount_paid' => '',
        ])->assertSessionHasErrors(['amount_paid', 'payment_confirmed']);

        $this->post(route('sale.sales.complete', $sale), [
            'payment_method' => 'tunai', 'amount_paid' => 2000,
        ])->assertSessionHasErrors('payment_confirmed');

        $this->assertSame('pending', $sale->fresh()->status);
        $this->assertEquals(8, $stock->fresh()->quantity);
    }

    public function test_failed_edit_keeps_original_sale_and_stock(): void
    {
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 9, 29)->setTime(16, 0));
        [$product, $unit, $location, $stock] = $this->setupSale(false);
        $sale = $this->hold($product, $unit, $location);

        $this->from(route('sale.sales.edit', $sale))->put(route('sale.sales.update', $sale), [
            'sale_status' => 'pending', 'sale_date' => '2026-09-29',
            'transaction_confirmed' => 1,
            'payment_method' => 'tunai', 'amount_paid' => 0,
            'items' => [[
                'product_id' => $product->id, 'product_unit_id' => $unit->id,
                'quantity' => 20, 'unit_price' => 1000,
                'sources' => [['location_id' => $location->id, 'quantity' => 20]],
            ]],
        ])->assertRedirect(route('sale.sales.edit', $sale))->assertSessionHas('error');

        $this->assertEquals(2, $sale->items()->first()->quantity);
        $this->assertEquals(8, $stock->fresh()->quantity);
    }
}
