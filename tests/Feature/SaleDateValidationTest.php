<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, ProductUnit, Sale, Stock, StockMovement, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SaleDateValidationTest extends TestCase
{
    use RefreshDatabase;

    private array $payload;
    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();
        // Jakarta is already October 22 while UTC is still October 21.
        $this->travelTo(Carbon::parse('2026-10-21 18:30:00', 'UTC'));
        config(['app.timezone' => 'UTC']);
        $this->actingAs(User::create(['name' => 'Kasir', 'email' => 'sale-date@example.com', 'password' => 'password', 'role' => 'kasir']));
        $unit = Unit::create(['name' => 'Botol']);
        $product = Product::create([
            'name' => 'Stok Lama', 'category_id' => Category::create(['name' => 'Minuman'])->id,
            'buy_unit_id' => $unit->id, 'buy_price' => 700, 'sell_price_wholesale' => 1000, 'is_active' => true,
        ]);
        $productUnit = ProductUnit::create([
            'product_id' => $product->id, 'unit_id' => $unit->id, 'conversion_to_base' => 1,
            'buy_price' => 700, 'sell_price' => 1000, 'is_base_unit' => true, 'is_default_purchase' => true, 'is_default_sale' => true,
        ]);
        $location = Location::create(['name' => 'Toko']);
        $this->stock = Stock::create(['product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 10]);
        $this->payload = [
            'sale_status' => 'paid', 'sale_date' => '2026-10-22', 'transaction_confirmed' => 1,
            'payment_method' => 'tunai', 'amount_paid' => 2000,
            'items' => [[
                'product_id' => $product->id, 'product_unit_id' => $productUnit->id, 'quantity' => 2, 'unit_price' => 1000,
                'sources' => [['location_id' => $location->id, 'quantity' => 2]],
            ]],
        ];
    }

    public static function futureDates(): array
    {
        return [
            ['paid', '2026-10-23'], ['pending', '2026-10-23'],
            ['paid', '2099-01-01'], ['pending', '2099-01-01'],
            ['paid', '2026-10-22T23:59:59'],
        ];
    }

    #[DataProvider('futureDates')]
    public function test_future_or_timestamp_dates_are_rejected_before_stock_changes(string $status, string $date): void
    {
        $this->post(route('sale.sales.store'), array_replace($this->payload, [
            'sale_status' => $status, 'sale_date' => $date, 'amount_paid' => $status === 'pending' ? 0 : 2000,
        ]))->assertSessionHasErrors('sale_date');
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame(10.0, (float) $this->stock->fresh()->quantity);
    }

    public static function allowedDates(): array
    {
        return [['paid', '2026-10-22'], ['pending', '2026-10-22'], ['paid', '2026-10-21'], ['pending', '2026-10-21']];
    }

    #[DataProvider('allowedDates')]
    public function test_today_in_jakarta_and_past_dates_remain_valid(string $status, string $date): void
    {
        $this->assertSame('2026-10-21', now('UTC')->toDateString());
        $this->post(route('sale.sales.store'), array_replace($this->payload, [
            'sale_status' => $status, 'sale_date' => $date, 'amount_paid' => $status === 'pending' ? 0 : 2000,
        ]))->assertSessionHasNoErrors()->assertRedirect(route('sale.sales.index'));
        $this->assertSame($date, Sale::firstOrFail()->sale_date->toDateString());
        $this->assertSame(8.0, (float) $this->stock->fresh()->quantity);
    }

    private function hold(): Sale
    {
        $this->post(route('sale.sales.store'), array_replace($this->payload, ['sale_status' => 'pending', 'amount_paid' => 0]))
            ->assertSessionHasNoErrors();
        return Sale::firstOrFail();
    }

    public function test_editing_to_a_future_date_preserves_note_items_and_reserved_stock(): void
    {
        $sale = $this->hold();
        $before = $sale->getAttributes();
        $itemIds = $sale->items()->pluck('id')->all();
        $movements = StockMovement::count();
        $this->put(route('sale.sales.update', $sale), array_replace($this->payload, ['sale_date' => '2099-01-01']))
            ->assertSessionHasErrors('sale_date');
        $this->assertSame($before, $sale->fresh()->getAttributes());
        $this->assertSame($itemIds, $sale->items()->pluck('id')->all());
        $this->assertSame($movements, StockMovement::count());
        $this->assertSame(8.0, (float) $this->stock->fresh()->quantity);
    }

    public function test_legacy_future_pending_sale_must_be_corrected_before_payment(): void
    {
        $sale = $this->hold();
        $sale->update(['sale_date' => '2099-01-01']);
        $this->post(route('sale.sales.complete', $sale), [
            'payment_method' => 'tunai', 'amount_paid' => 2000, 'payment_confirmed' => 1,
        ])->assertSessionHasErrors('sale_date');
        $this->assertSame('pending', $sale->fresh()->status);
        $this->assertSame(0.0, (float) $sale->fresh()->amount_paid);
        $this->assertSame(8.0, (float) $this->stock->fresh()->quantity);
    }

    public function test_create_and_edit_forms_limit_date_to_today_in_jakarta(): void
    {
        $sale = $this->hold();
        foreach ([route('sale.sales.create'), route('sale.sales.edit', $sale)] as $url) {
            $response = $this->get($url)->assertOk();
            $document = new \DOMDocument;
            @$document->loadHTML($response->getContent());
            $input = (new \DOMXPath($document))->query('//input[@name="sale_date"]')->item(0);
            $this->assertSame('2026-10-22', $input->getAttribute('max'));
        }
    }
}
