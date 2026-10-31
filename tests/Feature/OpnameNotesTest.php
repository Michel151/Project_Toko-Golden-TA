<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, Stock, StockBatch, StockOpname, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpnameNotesTest extends TestCase
{
    use RefreshDatabase;

    private Location $location;
    private Product $ordinary;
    private Product $expirable;
    private StockOpname $opname;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create(['name'=>'Petugas', 'email'=>'opname-notes@example.com', 'password'=>'password', 'role'=>'staf_gudang']));
        $this->location = Location::create(['name'=>'Gudang']);
        $category = Category::create(['name'=>'Kategori']);
        $unit = Unit::create(['name'=>'Pcs']);
        $attributes = ['category_id'=>$category->id, 'buy_unit_id'=>$unit->id, 'buy_price'=>1000, 'sell_price_wholesale'=>1500];
        $this->ordinary = Product::create($attributes + ['name'=>'Barang Biasa', 'is_expirable'=>false]);
        $this->expirable = Product::create($attributes + ['name'=>'Barang Berkedaluwarsa', 'is_expirable'=>true]);
        foreach ([$this->ordinary, $this->expirable] as $product) {
            Stock::create(['product_id'=>$product->id, 'location_id'=>$this->location->id, 'quantity'=>10]);
        }
        foreach ([today()->addMonths(2), today()->addMonths(3)] as $date) {
            StockBatch::create(['product_id'=>$this->expirable->id, 'location_id'=>$this->location->id,
                'received_date'=>today(), 'expired_date'=>$date, 'quantity_initial'=>5, 'quantity_remaining'=>5]);
        }
        $this->post(route('inventory.opnames.store'), ['location_id'=>$this->location->id, 'opname_date'=>today()->toDateString()])
            ->assertSessionHasNoErrors();
        $this->opname = StockOpname::firstOrFail();
    }

    private function payload(): array
    {
        $items = [];
        foreach ($this->opname->items()->with('expiryCounts')->get() as $item) {
            $items[$item->id] = ['physical_quantity'=>$item->physical_quantity];
            if ($item->tracks_expiry) {
                $items[$item->id]['expiries'] = $item->expiryCounts->map(fn ($row) => [
                    'id'=>$row->id, 'expired_date'=>$row->expired_date?->toDateString(), 'physical_quantity'=>$row->physical_quantity,
                ])->all();
            }
        }
        return ['items'=>$items];
    }

    private function item(Product $product)
    {
        return $this->opname->items()->where('product_id', $product->id)->firstOrFail();
    }

    public function test_draft_allows_missing_notes_for_quantity_and_expiry_differences(): void
    {
        $data = $this->payload();
        $ordinary = $this->item($this->ordinary);
        $expiry = $this->item($this->expirable);
        $data['items'][$ordinary->id]['physical_quantity'] = 8;
        $data['items'][$expiry->id]['expiries'][0]['physical_quantity'] = 3;
        $data['items'][$expiry->id]['expiries'][1]['physical_quantity'] = 7;
        $this->put(route('inventory.opnames.update', $this->opname), $data)->assertSessionHasNoErrors();
        $this->assertEquals(-2, $ordinary->fresh()->difference);
        $this->assertNull($ordinary->fresh()->notes);
        $this->assertEquals(0, $expiry->fresh()->difference);
        $this->assertTrue($expiry->fresh()->hasCountDifference());
        $this->assertSame('draft', $this->opname->fresh()->status);
        $this->assertEquals(20, Stock::sum('quantity'));
        $this->assertDatabaseCount('stock_movements', 0);
        $this->postJson(route('inventory.opnames.complete', $this->opname), $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors(["items.{$ordinary->id}.notes", "items.{$expiry->id}.notes"]);
    }

    public function test_completion_rejects_missing_or_whitespace_notes_for_short_and_over_counts(): void
    {
        $item = $this->item($this->ordinary);
        foreach ([8, 12] as $quantity) {
            foreach ([null, '', '   '] as $notes) {
                $data = $this->payload();
                $data['items'][$item->id] = ['physical_quantity'=>$quantity, 'notes'=>$notes];
                $this->postJson(route('inventory.opnames.complete', $this->opname), $data)
                    ->assertUnprocessable()->assertJsonValidationErrors("items.{$item->id}.notes");
                $this->assertEquals(10, $item->fresh()->physical_quantity);
                $this->assertEquals(20, Stock::sum('quantity'));
                $this->assertDatabaseCount('stock_movements', 0);
            }
        }
    }

    public function test_expiry_only_change_requires_notes_and_rolls_back_all_items_on_failure(): void
    {
        $ordinary = $this->item($this->ordinary);
        $expiry = $this->item($this->expirable);
        $data = $this->payload();
        $data['items'][$ordinary->id] = ['physical_quantity'=>9, 'notes'=>'Barang pecah'];
        $data['items'][$expiry->id]['expiries'][0]['physical_quantity'] = 0;
        $data['items'][$expiry->id]['expiries'][1]['physical_quantity'] = 10;
        $this->postJson(route('inventory.opnames.complete', $this->opname), $data)->assertUnprocessable()
            ->assertJsonValidationErrors("items.{$expiry->id}.notes");
        $this->assertEquals(10, $ordinary->fresh()->physical_quantity);
        $this->assertNull($ordinary->fresh()->notes);
        $this->assertEquals([5, 5], $expiry->expiryCounts()->pluck('physical_quantity')->map(fn ($qty)=>(float)$qty)->all());
        $this->assertEquals(20, Stock::sum('quantity'));
        $this->assertEquals(10, StockBatch::sum('quantity_remaining'));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_notes_are_saved_restored_and_shown_with_escaping_then_locked_after_completion(): void
    {
        $item = $this->item($this->ordinary);
        $data = $this->payload();
        $notes = "Barang pecah saat pemeriksaan\n<script>alert(1)</script>";
        $data['items'][$item->id] = ['physical_quantity'=>9, 'notes'=>'  '.$notes.'  '];
        $this->put(route('inventory.opnames.update', $this->opname), $data)->assertSessionHasNoErrors();
        $this->assertSame($notes, $item->fresh()->notes);
        $this->get(route('inventory.opnames.edit', $this->opname))->assertOk()->assertSee('Keterangan')
            ->assertSee($notes)->assertDontSee('<script>alert(1)</script>', false);
        // Completion can reuse an explanation already saved in the draft.
        $this->post(route('inventory.opnames.complete', $this->opname), $this->payload())->assertSessionHasNoErrors();
        $this->get(route('inventory.opnames.show', $this->opname))->assertOk()->assertSee('Keterangan')
            ->assertSee($notes)->assertDontSee('<script>alert(1)</script>', false);
        $this->assertSame('completed', $this->opname->fresh()->status);
        $this->assertEquals(9, Stock::where('product_id', $this->ordinary->id)->value('quantity'));
        $data['items'][$item->id]['notes'] = 'Diganti setelah selesai';
        $this->put(route('inventory.opnames.update', $this->opname), $data)->assertSessionHas('error');
        $this->assertSame($notes, $item->fresh()->notes);
    }

    public function test_notes_allow_expiry_redistribution_and_new_expiry_dates_with_unchanged_total(): void
    {
        $item = $this->item($this->expirable);
        $data = $this->payload();
        $data['items'][$item->id]['expiries'][0]['physical_quantity'] = 0;
        $data['items'][$item->id]['expiries'][] = ['expired_date'=>today()->addMonths(4)->toDateString(), 'physical_quantity'=>5];
        $this->postJson(route('inventory.opnames.complete', $this->opname), $data)->assertUnprocessable()
            ->assertJsonValidationErrors("items.{$item->id}.notes");
        $data['items'][$item->id]['notes'] = 'Koreksi tanggal kedaluwarsa yang sebelumnya salah dicatat.';
        $this->post(route('inventory.opnames.complete', $this->opname), $data)->assertSessionHasNoErrors();
        $this->assertEquals(10, Stock::where('product_id', $this->expirable->id)->value('quantity'));
        $this->assertEquals(0, $item->fresh()->difference);
        $this->assertDatabaseHas('stock_movements', ['movement_type'=>'opname_adjustment', 'quantity'=>0]);
        $this->get(route('inventory.opnames.show', [$this->opname, 'filter'=>'changed']))->assertOk()
            ->assertSee($data['items'][$item->id]['notes']);
    }

    public function test_matching_counts_can_complete_without_notes_and_old_documents_remain_readable(): void
    {
        $this->post(route('inventory.opnames.complete', $this->opname), $this->payload())->assertSessionHasNoErrors();
        $this->assertSame('completed', $this->opname->fresh()->status);
        $this->get(route('inventory.opnames.show', $this->opname))->assertOk()->assertSee('Keterangan')->assertSee('—');
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertEquals(20, Stock::sum('quantity'));
    }

    public function test_optional_notes_can_be_saved_and_cleared_and_invalid_types_or_lengths_are_rejected(): void
    {
        $item = $this->item($this->ordinary);
        $data = $this->payload();
        foreach ([['bad'], str_repeat('x', 1001)] as $notes) {
            $data['items'][$item->id]['notes'] = $notes;
            $this->putJson(route('inventory.opnames.update', $this->opname), $data)->assertUnprocessable()
                ->assertJsonValidationErrors("items.{$item->id}.notes");
        }
        $data['items'][$item->id]['notes'] = 'Sudah dihitung dua kali.';
        $this->put(route('inventory.opnames.update', $this->opname), $data)->assertSessionHasNoErrors();
        $this->assertSame('Sudah dihitung dua kali.', $item->fresh()->notes);
        $data['items'][$item->id]['notes'] = '';
        $this->put(route('inventory.opnames.update', $this->opname), $data)->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->notes);
    }
}
