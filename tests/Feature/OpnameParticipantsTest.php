<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, ProductUnit, Stock, StockOpname, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OpnameParticipantsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $staff;
    private User $cashier;
    private Location $location;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-23 10:00:00', 'Asia/Jakarta'));
        $this->owner = User::create(['name'=>'Owner Golden','email'=>'participant-owner@test.com','password'=>'password','role'=>'owner']);
        $this->staff = User::create(['name'=>'Andi Gudang','email'=>'participant-staff@test.com','password'=>'password','role'=>'staf_gudang']);
        $this->cashier = User::create(['name'=>'Budi Kasir','email'=>'participant-cashier@test.com','password'=>'password','role'=>'kasir']);
        $this->actingAs($this->owner);
        $this->location = Location::create(['name'=>'Gudang 1']);
        $unit = Unit::create(['name'=>'Pcs']);
        $category = Category::create(['name'=>'Barang Uji']);
        $this->product = Product::create(['name'=>'Produk Opname','category_id'=>$category->id,'buy_unit_id'=>$unit->id,'buy_price'=>1000,'sell_price_wholesale'=>1500]);
        ProductUnit::create(['product_id'=>$this->product->id,'unit_id'=>$unit->id,'conversion_to_base'=>1,'is_base_unit'=>true]);
        Stock::create(['product_id'=>$this->product->id,'location_id'=>$this->location->id,'quantity'=>7]);
    }

    private function payload(): array
    {
        return ['location_id'=>$this->location->id,'opname_date'=>'2026-10-23','participant_ids'=>[$this->cashier->id,$this->staff->id]];
    }

    private function capture(string $name, $page): void
    {
        if (getenv('CAPTURE_OPNAME_PARTICIPANTS_UI') !== '1') return;
        $directory = storage_path('framework/testing/opname-participants');
        if (!is_dir($directory)) mkdir($directory, 0777, true);
        file_put_contents($directory.'/'.$name.'.html', $page->getContent());
    }

    public function test_form_has_multiple_select_with_sorted_names_and_defaults_to_current_user(): void
    {
        $page = $this->get(route('inventory.opnames.create'))->assertOk()->assertSee('Petugas Opname');
        $document = new \DOMDocument;
        @$document->loadHTML($page->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//select[@name="participant_ids[]" and @multiple and @required]')->length);
        $this->assertSame((string) $this->owner->id, $xpath->query('//select[@id="opnameParticipants"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertSame([$this->staff->id,$this->cashier->id,$this->owner->id], $page->viewData('users')->pluck('id')->all());
        $this->capture('create', $page);
        $page = $this->withSession(['_old_input'=>$this->payload()])->get(route('inventory.opnames.create'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($page->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(2, $xpath->query('//select[@id="opnameParticipants"]/option[@selected]')->length);
        $this->capture('restored', $page);
    }

    public function test_multiple_participants_are_saved_and_displayed_without_changing_stock_or_permissions(): void
    {
        $permissions = DB::table('role_permissions')->orderBy('id')->get()->toJson();
        $this->actingAs($this->staff)->post(route('inventory.opnames.store'), $this->payload()+['participants'=>[['name'=>'Nama Palsu']]])->assertSessionHasNoErrors()->assertRedirect();
        $opname = StockOpname::firstOrFail();
        $this->assertSame([
            ['user_id'=>$this->staff->id,'name'=>'Andi Gudang'],
            ['user_id'=>$this->cashier->id,'name'=>'Budi Kasir'],
        ], $opname->participants);
        $this->assertEquals(7, Stock::first()->quantity);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame($permissions, DB::table('role_permissions')->orderBy('id')->get()->toJson());
        $page = $this->get(route('inventory.opnames.edit', $opname))->assertOk()->assertSee('Andi Gudang, Budi Kasir')->assertDontSee('Nama Palsu');
        $this->capture('edit', $page);
        $page = $this->get(route('inventory.opnames.show', $opname))->assertOk()->assertSee('Andi Gudang, Budi Kasir');
        $this->capture('show', $page);
        $this->actingAs($this->cashier)->get(route('inventory.opnames.show', $opname))->assertForbidden();
    }

    public function test_invalid_participant_lists_do_not_create_opnames(): void
    {
        foreach ([[], [$this->staff->id,$this->staff->id], [999999], ['unknown'], [['id'=>$this->staff->id]], 'not-an-array'] as $ids) {
            $payload = $this->payload();
            $payload['participant_ids'] = $ids;
            $this->postJson(route('inventory.opnames.store'), $payload)->assertUnprocessable();
            $this->assertDatabaseCount('stock_opnames', 0);
        }
    }

    public function test_older_clients_default_to_creator_and_legacy_documents_remain_readable(): void
    {
        $payload = $this->payload();
        unset($payload['participant_ids']);
        $this->post(route('inventory.opnames.store'), $payload)->assertSessionHasNoErrors();
        $opname = StockOpname::firstOrFail();
        $this->assertSame([['user_id'=>$this->owner->id,'name'=>'Owner Golden']], $opname->participants);
        $opname->update(['participants'=>null]);
        $page = $this->get(route('inventory.opnames.show', $opname))->assertOk()->assertSee('Belum dicatat');
        $this->capture('legacy', $page);
    }

    public function test_names_remain_historical_and_counting_or_completion_does_not_replace_participants(): void
    {
        $this->post(route('inventory.opnames.store'), $this->payload())->assertSessionHasNoErrors();
        $opname = StockOpname::firstOrFail();
        $original = $opname->participants;
        $this->staff->update(['name'=>'Nama Baru']);
        $this->cashier->delete();
        $item = $opname->items()->firstOrFail();
        $data = ['items'=>[$item->id=>['physical_quantity'=>7]],'participant_ids'=>[$this->owner->id],'participants'=>[]];
        $this->put(route('inventory.opnames.update', $opname), $data)->assertSessionHasNoErrors();
        $this->assertSame($original, $opname->fresh()->participants);
        $this->post(route('inventory.opnames.complete', $opname), $data)->assertSessionHasNoErrors();
        $this->assertSame('completed', $opname->fresh()->status);
        $this->assertSame($original, $opname->fresh()->participants);
        $this->get(route('inventory.opnames.show', $opname))->assertOk()->assertSee('Andi Gudang, Budi Kasir')->assertDontSee('Nama Baru');
    }

    public function test_unpermitted_user_cannot_create_an_opname_by_adding_their_name(): void
    {
        $this->actingAs($this->cashier)->post(route('inventory.opnames.store'), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('stock_opnames', 0);
    }
}
