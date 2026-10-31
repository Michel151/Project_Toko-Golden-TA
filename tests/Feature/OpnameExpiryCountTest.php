<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, ProductUnit, Stock, StockBatch, StockMovement, StockOpname, StockOpnameItem, Unit, User};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpnameExpiryCountTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private Product $ordinary;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-17 10:00:00','Asia/Jakarta'));
        $this->actingAs(User::create(['name'=>'Staf Gudang','email'=>'expiry-opname@example.com','password'=>'password','role'=>'staf_gudang']));
        $this->location=Location::create(['name'=>'Gudang 1']);
        $category=Category::create(['name'=>'Kebutuhan Toko']);
        $pcs=Unit::create(['name'=>'Pcs']);
        $dus=Unit::create(['name'=>'Dus']);
        $this->product=Product::create(['name'=>'A Susu Uji','category_id'=>$category->id,'buy_unit_id'=>$dus->id,
            'buy_price'=>12000,'sell_price_wholesale'=>15000,'can_retail'=>true,'retail_unit_id'=>$pcs->id,
            'conversion_factor'=>10,'is_expirable'=>true,'is_active'=>true]);
        ProductUnit::create(['product_id'=>$this->product->id,'unit_id'=>$pcs->id,'conversion_to_base'=>1,'is_base_unit'=>true]);
        ProductUnit::create(['product_id'=>$this->product->id,'unit_id'=>$dus->id,'conversion_to_base'=>10,'is_default_purchase'=>true]);
        $this->ordinary=Product::create(['name'=>'B Barang Tanpa Kedaluwarsa','category_id'=>$category->id,'buy_unit_id'=>$pcs->id,
            'buy_price'=>1000,'sell_price_wholesale'=>1500,'is_expirable'=>false]);
        Stock::create(['product_id'=>$this->product->id,'location_id'=>$this->location->id,'quantity'=>20]);
        Stock::create(['product_id'=>$this->ordinary->id,'location_id'=>$this->location->id,'quantity'=>30]);
        foreach ([['2026-10-16',10],['2026-12-01',10]] as [$date,$qty]) $this->batch($date,$qty);
    }

    private function batch(?string $date, float $quantity): StockBatch
    {
        return StockBatch::create(['product_id'=>$this->product->id,'location_id'=>$this->location->id,
            'received_date'=>'2026-10-01','expired_date'=>$date,'quantity_initial'=>$quantity,'quantity_remaining'=>$quantity]);
    }

    private function draft(): StockOpname
    {
        $this->post(route('inventory.opnames.store'),['location_id'=>$this->location->id,'opname_date'=>'2026-10-17'])
            ->assertSessionHasNoErrors()->assertRedirect();
        return StockOpname::latest('id')->first();
    }

    private function data(StockOpname $opname, array $counts = [], array $new = []): array
    {
        $items=[];
        foreach ($opname->items()->with('expiryCounts')->get() as $item) {
            $items[$item->id]=['physical_quantity'=>$item->physical_quantity, 'notes'=>'Penyesuaian berdasarkan pemeriksaan fisik.'];
            if (!$item->tracks_expiry) continue;
            $items[$item->id]['physical_quantity']=999;
            $items[$item->id]['expiries']=$item->expiryCounts->map(fn ($row)=>[
                'id'=>$row->id,'expired_date'=>$row->expired_date?->toDateString(),
                'physical_quantity'=>$counts[$row->expired_date?->toDateString() ?? ''] ?? $row->physical_quantity,
            ])->all();
            foreach ($new as $date=>$quantity) $items[$item->id]['expiries'][]=['expired_date'=>$date,'physical_quantity'=>$quantity];
        }
        return ['items'=>$items];
    }

    private function stock(): float { return (float) Stock::where('product_id',$this->product->id)->value('quantity'); }
    private function at(string $date): float { return (float) StockBatch::where('product_id',$this->product->id)->whereDate('expired_date',$date)->sum('quantity_remaining'); }

    private function capture(string $name, $response): void
    {
        if (getenv('CAPTURE_OPNAME_UI')!=='1') return;
        $directory=storage_path('framework/testing/opname-expiry');
        if (!is_dir($directory)) mkdir($directory,0777,true);
        file_put_contents($directory.'/'.$name.'.html',$response->getContent());
    }

    public function test_counting_expired_stock_as_zero_preserves_all_fresh_stock(): void
    {
        $opname=$this->draft();
        $page=$this->get(route('inventory.opnames.edit',$opname))->assertOk()->assertSee('Rincian Kedaluwarsa')
            ->assertSee('16/10/2026')->assertSee('01/12/2026')->assertSee('Tambah Kedaluwarsa');
        $this->capture('edit',$page);
        $data=$this->data($opname,['2026-10-16'=>0,'2026-12-01'=>10]);
        $this->put(route('inventory.opnames.update',$opname),$data)->assertSessionHasNoErrors();
        $this->assertEquals(20,$this->stock());
        $this->assertEquals(10,$this->at('2026-10-16'));
        $this->assertSame('draft',$opname->fresh()->status);
        $this->post(route('inventory.opnames.complete',$opname),$this->data($opname))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertEquals(10,$this->stock());
        $this->assertEquals(0,$this->at('2026-10-16'));
        $this->assertEquals(10,$this->at('2026-12-01'));
        $this->assertEquals(10,app(\App\Services\StockService::class)->sellableStockAt($this->product,$this->location));
        $this->assertSame('completed',$opname->fresh()->status);
        $this->assertDatabaseHas('stock_movements',['movement_type'=>'opname_adjustment','quantity'=>-10]);
        $this->capture('show',$this->get(route('inventory.opnames.show',$opname))->assertOk()->assertSee('Rincian Kedaluwarsa')->assertSee('16/10/2026'));
    }

    public function test_count_and_result_pages_use_all_registered_unit_levels(): void
    {
        $slop=Unit::create(['name'=>'Slop']);
        ProductUnit::create(['product_id'=>$this->product->id,'unit_id'=>$slop->id,'conversion_to_base'=>5]);
        $opname=$this->draft();
        $data=$this->data($opname,['2026-10-16'=>7.25,'2026-12-01'=>10]);
        $this->put(route('inventory.opnames.update',$opname),$data)->assertSessionHasNoErrors();
        $page=$this->get(route('inventory.opnames.edit',$opname))->assertOk()
            ->assertSee('2 Dus')->assertSee('1 Dus + 1 Slop + 2,25 Pcs')
            ->assertSee('aria-label="Slop A Susu Uji"',false);
        $this->capture('multi-edit',$page);
        $this->get(route('inventory.opnames.show',$opname))->assertOk()
            ->assertSee('1 Slop + 2,25 Pcs')->assertSee('-2,75 Pcs');
        $this->assertEquals(20,$this->stock());
    }

    public function test_result_page_paginates_large_lists_without_loading_all_items(): void
    {
        $opname=$this->draft();
        for ($i=1;$i<=75;$i++) {
            $product=$this->ordinary->replicate();
            $product->name='Barang Uji '.str_pad($i,3,'0',STR_PAD_LEFT);
            $product->save();
            $opname->items()->create(['product_id'=>$product->id,'system_quantity'=>30,'physical_quantity'=>30,'difference'=>0]);
        }
        $page=$this->get(route('inventory.opnames.show',$opname))->assertOk()
            ->assertViewHas('items',fn($items)=>$items->count()===25 && $items->total()===77)
            ->assertViewHas('summary',['total'=>77,'changed'=>0,'matched'=>77]);
        $this->capture('show-large',$page);
        $this->get(route('inventory.opnames.show',[$opname,'page'=>2,'per_page'=>50]))->assertOk()
            ->assertViewHas('items',fn($items)=>$items->count()===27 && $items->currentPage()===2);
        $this->assertEquals(20,$this->stock());
    }

    public function test_result_filters_and_search_include_expiry_only_differences(): void
    {
        $opname=$this->draft();
        $this->put(route('inventory.opnames.update',$opname),$this->data($opname,['2026-10-16'=>0,'2026-12-01'=>20]))->assertSessionHasNoErrors();
        $this->get(route('inventory.opnames.show',[$opname,'filter'=>'changed']))->assertOk()
            ->assertViewHas('items',fn($items)=>$items->total()===1)
            ->assertViewHas('summary',['total'=>2,'changed'=>1,'matched'=>1])->assertSee('Rincian berubah');
        $this->get(route('inventory.opnames.show',[$opname,'filter'=>'matched']))->assertOk()
            ->assertViewHas('items',fn($items)=>$items->total()===1 && $items->first()->product_id===$this->ordinary->id);
        $this->get(route('inventory.opnames.show',[$opname,'q'=>'Susu']))->assertOk()
            ->assertViewHas('items',fn($items)=>$items->total()===1)->assertViewHas('summary.total',2);
        $this->get(route('inventory.opnames.show',[$opname,'q'=>'Tidak ditemukan']))->assertOk()
            ->assertSee('Tidak ada barang yang sesuai pencarian atau filter.');
    }

    public function test_result_short_and_over_filters_are_scoped_to_the_document(): void
    {
        $opname=$this->draft();
        $opname->items()->where('product_id',$this->product->id)->update(['physical_quantity'=>15,'difference'=>-5]);
        $opname->items()->where('product_id',$this->ordinary->id)->update(['physical_quantity'=>35,'difference'=>5]);
        foreach (['short'=>$this->product->id,'over'=>$this->ordinary->id] as $filter=>$id) {
            $this->get(route('inventory.opnames.show',[$opname,'filter'=>$filter]))->assertOk()
                ->assertViewHas('items',fn($items)=>$items->total()===1 && $items->first()->product_id===$id);
        }
        $this->get(route('inventory.opnames.show',[$opname,'per_page'=>1000]))->assertSessionHasErrors('per_page');
    }

    public function test_expiry_distribution_can_be_corrected_even_when_the_total_stays_the_same(): void
    {
        $opname=$this->draft();
        $this->post(route('inventory.opnames.complete',$opname),$this->data($opname,['2026-10-16'=>0,'2026-12-01'=>20]))->assertSessionHasNoErrors();
        $this->assertEquals(20,$this->stock());
        $this->assertEquals(0,$this->at('2026-10-16'));
        $this->assertEquals(20,$this->at('2026-12-01'));
        $this->assertDatabaseHas('stock_movements',['movement_type'=>'opname_adjustment','quantity'=>0]);
        $this->assertSame(0,StockBatch::whereNull('expired_date')->count());
    }

    public function test_new_expiry_dates_and_fractional_counts_are_saved_explicitly(): void
    {
        $opname=$this->draft();
        $this->post(route('inventory.opnames.complete',$opname),$this->data($opname,['2026-10-16'=>0,'2026-12-01'=>12.25],['2027-01-01'=>3.5]))->assertSessionHasNoErrors();
        $this->assertEquals(15.75,$this->stock());
        $this->assertEquals(12.25,$this->at('2026-12-01'));
        $this->assertEquals(3.5,$this->at('2027-01-01'));
        $this->assertSame(0,StockBatch::whereNull('expired_date')->count());
    }

    public function test_entries_are_grouped_by_date_without_losing_stock_receipt_metadata(): void
    {
        StockBatch::whereDate('expired_date','2026-10-16')->delete();
        $first=$this->batch('2026-10-16',4);
        $second=$this->batch('2026-10-16',6);
        $first->update(['batch_code'=>'HISTORY-KEEP']);
        $opname=$this->draft();
        $item=$opname->items()->where('product_id',$this->product->id)->first();
        $this->assertSame(2,$item->expiryCounts()->count());
        $this->post(route('inventory.opnames.complete',$opname),$this->data($opname,['2026-10-16'=>3]))->assertSessionHasNoErrors();
        $this->assertEquals(3,$this->at('2026-10-16'));
        $this->assertEquals(10,$this->at('2026-12-01'));
        $this->assertEquals(4,$first->fresh()->quantity_initial);
        $this->assertSame('HISTORY-KEEP',$first->fresh()->batch_code);
        $this->assertNotNull($second->fresh());
    }

    public function test_stock_changes_after_the_draft_block_completion_without_overwriting_new_stock(): void
    {
        $opname=$this->draft();
        Stock::where('product_id',$this->product->id)->update(['quantity'=>19]);
        $this->post(route('inventory.opnames.complete',$opname),$this->data($opname,['2026-10-16'=>0]))->assertSessionHasErrors('items');
        $this->assertEquals(19,$this->stock());
        $this->assertEquals(10,$this->at('2026-10-16'));
        $this->assertSame('draft',$opname->fresh()->status);
        $this->assertDatabaseCount('stock_movements',0);
    }

    public function test_changed_expiry_details_are_detected_even_if_the_total_has_not_changed(): void
    {
        $opname=$this->draft();
        StockBatch::whereDate('expired_date','2026-10-16')->update(['quantity_remaining'=>9]);
        StockBatch::whereDate('expired_date','2026-12-01')->update(['quantity_remaining'=>11]);
        $this->post(route('inventory.opnames.complete',$opname),$this->data($opname))->assertSessionHasErrors('items');
        $this->assertEquals(20,$this->stock());
        $this->assertEquals(9,$this->at('2026-10-16'));
        $this->assertEquals(11,$this->at('2026-12-01'));
    }

    public function test_total_only_requests_and_omitted_expiry_rows_are_rejected(): void
    {
        $opname=$this->draft();
        $item=$opname->items()->where('product_id',$this->product->id)->first();
        $data=$this->data($opname);
        unset($data['items'][$item->id]['expiries']);
        $this->post(route('inventory.opnames.complete',$opname),$data)->assertSessionHasErrors('items');
        $data=$this->data($opname);
        array_pop($data['items'][$item->id]['expiries']);
        $this->post(route('inventory.opnames.complete',$opname),$data)->assertSessionHasErrors('items');
        $this->assertEquals(20,$this->stock());
    }

    public function test_duplicate_dates_invalid_dates_and_negative_values_are_rejected(): void
    {
        $opname=$this->draft();
        $item=$opname->items()->where('product_id',$this->product->id)->first();
        $this->post(route('inventory.opnames.complete',$opname),$this->data($opname,[],['2026-12-01'=>2]))->assertSessionHasErrors('items');
        foreach (['2026-02-31',null] as $date) {
            $data=$this->data($opname);
            $data['items'][$item->id]['expiries'][]=['expired_date'=>$date,'physical_quantity'=>2];
            $this->post(route('inventory.opnames.complete',$opname),$data)->assertSessionHasErrors();
        }
        $data=$this->data($opname,['2026-10-16'=>-1]);
        $this->post(route('inventory.opnames.complete',$opname),$data)->assertSessionHasErrors();
        $this->assertEquals(20,$this->stock());
        $this->assertDatabaseCount('stock_movements',0);
    }

    public function test_rows_from_other_documents_or_products_are_rejected(): void
    {
        $opname=$this->draft();
        $other=$this->draft();
        $item=$opname->items()->where('product_id',$this->product->id)->first();
        $foreign=$other->items()->where('product_id',$this->product->id)->first()->expiryCounts()->first();
        $data=$this->data($opname);
        $data['items'][$item->id]['expiries'][0]['id']=$foreign->id;
        $this->post(route('inventory.opnames.complete',$opname),$data)->assertSessionHasErrors('items');
        $data=$this->data($opname);
        $data['items'][99999]=['physical_quantity'=>1];
        $this->post(route('inventory.opnames.complete',$opname),$data)->assertSessionHasErrors('items');
        $this->assertEquals(20,$this->stock());
    }

    public function test_regular_products_keep_total_quantity_entry_without_expiry_rows(): void
    {
        $opname=$this->draft();
        $data=$this->data($opname);
        $item=$opname->items()->where('product_id',$this->ordinary->id)->first();
        $data['items'][$item->id]['physical_quantity']=25;
        $this->post(route('inventory.opnames.complete',$opname),$data)->assertSessionHasNoErrors();
        $this->assertEquals(25,Stock::where('product_id',$this->ordinary->id)->value('quantity'));
        $this->assertSame(0,$item->expiryCounts()->count());
        $this->assertEquals(20,$this->stock());
    }

    public function test_failure_on_another_product_rolls_back_all_expiry_adjustments(): void
    {
        $opname=$this->draft();
        Stock::where('product_id',$this->ordinary->id)->update(['quantity'=>29]);
        $this->post(route('inventory.opnames.complete',$opname),$this->data($opname,['2026-10-16'=>0]))->assertSessionHasErrors('items');
        $this->assertEquals(20,$this->stock());
        $this->assertEquals(10,$this->at('2026-10-16'));
        $this->assertDatabaseCount('stock_movements',0);
    }

    public function test_undated_stock_must_be_assigned_a_date_before_completion(): void
    {
        StockBatch::query()->delete();
        $opname=$this->draft();
        $this->post(route('inventory.opnames.complete',$opname),$this->data($opname))->assertSessionHasErrors('items');
        $this->assertEquals(20,$this->stock());
        $this->post(route('inventory.opnames.complete',$opname),$this->data($opname,[''=>0],['2026-12-15'=>20]))->assertSessionHasNoErrors();
        $this->assertEquals(20,$this->at('2026-12-15'));
        $this->assertSame(0,StockBatch::whereNull('expired_date')->count());
    }

    public function test_repeated_completion_does_not_reapply_the_counts(): void
    {
        $opname=$this->draft();
        $data=$this->data($opname,['2026-10-16'=>0]);
        $this->post(route('inventory.opnames.complete',$opname),$data)->assertSessionHasNoErrors();
        $this->post(route('inventory.opnames.complete',$opname),$data)->assertSessionHas('error');
        $this->assertEquals(10,$this->stock());
        $this->assertDatabaseCount('stock_movements',1);
    }

    public function test_historical_completed_documents_stay_unchanged(): void
    {
        $opname=StockOpname::create(['opname_number'=>'OPN-OLD','location_id'=>$this->location->id,
            'opname_date'=>'2026-10-01','status'=>'completed','created_by'=>auth()->id()]);
        $item=$opname->items()->create(['product_id'=>$this->product->id,'system_quantity'=>25,'physical_quantity'=>20,'difference'=>-5]);
        $this->get(route('inventory.opnames.show',$opname))->assertOk()->assertSee('OPN-OLD');
        $this->assertNull($item->fresh()->tracks_expiry);
        $this->assertSame(0,$item->expiryCounts()->count());
        $this->assertEquals(20,$this->stock());
    }

    public function test_legacy_drafts_are_initialized_only_when_their_original_totals_are_unchanged(): void
    {
        $opname=StockOpname::create(['opname_number'=>'OPN-DRAFT-OLD','location_id'=>$this->location->id,
            'opname_date'=>'2026-10-17','status'=>'draft','created_by'=>auth()->id()]);
        $item=$opname->items()->create(['product_id'=>$this->product->id,'system_quantity'=>20,'physical_quantity'=>20,'difference'=>0]);
        $this->get(route('inventory.opnames.edit',$opname))->assertOk()->assertSee('Rincian Kedaluwarsa');
        $this->assertTrue($item->fresh()->tracks_expiry);
        $this->assertSame(2,$item->expiryCounts()->count());
        $this->assertEquals(20,$this->stock());
    }

    public function test_a_legacy_total_only_adjustment_is_not_guessed_into_expiry_rows(): void
    {
        $opname=StockOpname::create(['opname_number'=>'OPN-DRAFT-ADJUSTED','location_id'=>$this->location->id,
            'opname_date'=>'2026-10-17','status'=>'draft','created_by'=>auth()->id()]);
        $item=$opname->items()->create(['product_id'=>$this->product->id,'system_quantity'=>20,'physical_quantity'=>12,'difference'=>-8]);
        $this->get(route('inventory.opnames.edit',$opname))->assertRedirect(route('inventory.opnames.index'))->assertSessionHas('error');
        $this->assertEquals(12,$item->fresh()->physical_quantity);
        $this->assertNull($item->fresh()->tracks_expiry);
        $this->assertSame(0,$item->expiryCounts()->count());
    }

    public function test_expiry_dates_changed_after_capture_are_not_silently_overwritten(): void
    {
        $opname=$this->draft();
        StockBatch::whereDate('expired_date','2026-12-01')->update(['expired_date'=>'2027-01-01']);
        $this->post(route('inventory.opnames.complete',$opname),$this->data($opname))->assertSessionHasErrors('items');
        $this->assertEquals(10,$this->at('2027-01-01'));
        $this->assertEquals(20,$this->stock());
        $this->assertSame('draft',$opname->fresh()->status);
    }

    public function test_empty_expirable_stock_can_be_counted_without_creating_an_undated_balance(): void
    {
        StockBatch::query()->delete();
        Stock::where('product_id',$this->product->id)->update(['quantity'=>0]);
        $opname=$this->draft();
        $this->post(route('inventory.opnames.complete',$opname),$this->data($opname))->assertSessionHasNoErrors();
        $this->assertEquals(0,$this->stock());
        $this->assertDatabaseCount('stock_batches',0);
    }
}
