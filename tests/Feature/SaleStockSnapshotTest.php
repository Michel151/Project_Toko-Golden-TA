<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, ProductUnit, Sale, Stock, StockBatch, Unit, User};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SaleStockSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private ProductUnit $base;
    private ProductUnit $pack;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-17 10:00:00','Asia/Jakarta'));
        $this->actingAs(User::create(['name'=>'Owner','email'=>'stock-snapshot@example.com','password'=>'password','role'=>'owner']));
        $category=Category::create(['name'=>'Kategori']);
        $pcs=Unit::create(['name'=>'Pcs']);
        $dus=Unit::create(['name'=>'Dus']);
        $this->location=Location::create(['name'=>'Toko']);
        $this->product=Product::create(['name'=>'Barang Uji','category_id'=>$category->id,'buy_unit_id'=>$dus->id,
            'retail_unit_id'=>$pcs->id,'can_retail'=>true,'conversion_factor'=>12,'buy_price'=>12000,
            'sell_price_wholesale'=>24000,'sell_price_retail'=>2000,'is_active'=>true]);
        $this->base=ProductUnit::create(['product_id'=>$this->product->id,'unit_id'=>$pcs->id,'conversion_to_base'=>1,
            'contains_quantity'=>1,'buy_price'=>1000,'sell_price'=>2000,'is_base_unit'=>true]);
        $this->pack=ProductUnit::create(['product_id'=>$this->product->id,'unit_id'=>$dus->id,'conversion_to_base'=>12,
            'contains_quantity'=>12,'contains_unit_id'=>$this->base->id,'buy_price'=>12000,'sell_price'=>24000,
            'is_default_purchase'=>true,'is_default_sale'=>true]);
        Stock::create(['product_id'=>$this->product->id,'location_id'=>$this->location->id,'quantity'=>100]);
    }

    private function data(float $quantity = 1): array
    {
        return ['sale_status'=>'pending','sale_date'=>'2026-10-17','transaction_confirmed'=>1,
            'payment_method'=>'tunai','amount_paid'=>0,'items'=>[
                ['product_id'=>$this->product->id,'product_unit_id'=>$this->pack->id,'quantity'=>$quantity,
                    'unit_price'=>24000,'sources'=>[['location_id'=>$this->location->id,'quantity'=>$quantity]]],
            ]];
    }

    private function hold(): Sale
    {
        $this->post(route('sale.sales.store'),$this->data())->assertRedirect(route('sale.sales.index'));
        return Sale::firstOrFail();
    }

    private function stock(): float { return (float) Stock::firstOrFail()->quantity; }

    private function forceChangedConversion(): void
    {
        $this->pack->update(['conversion_to_base'=>24,'contains_quantity'=>24]);
        $this->product->update(['conversion_factor'=>24]);
    }

    private function productData(): array
    {
        return ['name'=>$this->product->name,'category_id'=>$this->product->category_id,'is_active'=>1,
            'base_unit_index'=>1,'default_purchase_unit_index'=>0,'default_sale_unit_index'=>0,
            'active_locations'=>[$this->location->id],'product_units'=>[
                ['unit_id'=>$this->pack->unit_id,'contains_quantity'=>12,'contains_unit_index'=>1,'buy_price'=>12000,'sell_price'=>24000],
                ['unit_id'=>$this->base->unit_id,'contains_quantity'=>1,'contains_unit_index'=>'','buy_price'=>1000,'sell_price'=>2000],
            ]];
    }

    public function test_manual_cancellation_restores_original_base_quantity_even_if_master_changes(): void
    {
        $sale=$this->hold();
        $this->assertEquals(88,$this->stock());
        $this->assertEquals(12,$sale->items()->first()->sources()->first()->base_quantity);
        $this->forceChangedConversion();
        $this->post(route('sale.sales.cancel',$sale))->assertRedirect();
        $this->assertEquals(100,$this->stock());
        $this->assertDatabaseHas('stock_movements',['movement_type'=>'sale_cancelled','quantity'=>12]);
        $this->post(route('sale.sales.cancel',$sale))->assertRedirect();
        $this->assertEquals(100,$this->stock());
    }

    public function test_expiry_sources_restore_the_exact_deduction_for_each_date(): void
    {
        $this->product->update(['is_expirable'=>true]);
        foreach ([['2026-11-01',7],['2026-12-01',93]] as [$date,$quantity]) {
            StockBatch::create(['product_id'=>$this->product->id,'location_id'=>$this->location->id,
                'received_date'=>'2026-10-01','expired_date'=>$date,'quantity_initial'=>$quantity,'quantity_remaining'=>$quantity]);
        }
        $sale=$this->hold();
        $this->assertEquals([7,5],$sale->items()->first()->sources()->orderBy('id')->pluck('base_quantity')->map(fn ($v)=>(float)$v)->all());
        $this->forceChangedConversion();
        $this->post(route('sale.sales.cancel',$sale))->assertRedirect();
        $this->assertEquals(100,$this->stock());
        $this->assertEquals([7,93],StockBatch::orderBy('id')->pluck('quantity_remaining')->map(fn ($v)=>(float)$v)->all());
    }

    public function test_automatic_cancellation_uses_the_saved_quantity(): void
    {
        $this->hold();
        $this->forceChangedConversion();
        $this->travelTo(Carbon::parse('2026-10-17 18:01:00','Asia/Jakarta'));
        Artisan::call('sales:cancel-overdue');
        Artisan::call('sales:cancel-overdue');
        $this->assertEquals(100,$this->stock());
        $this->assertSame('cancelled_auto',Sale::first()->status);
    }

    public function test_pending_edit_restores_the_old_quantity_and_saves_a_new_snapshot(): void
    {
        $sale=$this->hold();
        $this->forceChangedConversion();
        $this->put(route('sale.sales.update',$sale),$this->data(2))->assertRedirect(route('sale.sales.show',$sale));
        $this->assertEquals(52,$this->stock());
        $this->assertEquals(48,$sale->items()->first()->sources()->first()->base_quantity);
        $this->pack->update(['conversion_to_base'=>36]);
        $this->post(route('sale.sales.cancel',$sale))->assertRedirect();
        $this->assertEquals(100,$this->stock());
    }

    public function test_failed_edit_rolls_back_original_stock_and_snapshot(): void
    {
        $sale=$this->hold();
        $this->forceChangedConversion();
        $this->put(route('sale.sales.update',$sale),$this->data(20))->assertSessionHas('error');
        $this->assertEquals(88,$this->stock());
        $this->assertEquals(12,$sale->items()->first()->sources()->first()->base_quantity);
        $this->post(route('sale.sales.cancel',$sale))->assertRedirect();
        $this->assertEquals(100,$this->stock());
    }

    public function test_legacy_note_recovers_original_quantity_from_movements_not_current_units(): void
    {
        $sale=$this->hold();
        $sale->items()->first()->sources()->update(['base_quantity'=>null]);
        $this->forceChangedConversion();
        $this->post(route('sale.sales.cancel',$sale))->assertRedirect();
        $this->assertEquals(100,$this->stock());
        $this->assertEquals(12,$sale->items()->first()->sources()->first()->base_quantity);
    }

    public function test_legacy_note_without_reliable_history_is_not_restored_by_guessing(): void
    {
        $sale=$this->hold();
        $sale->items()->first()->sources()->update(['base_quantity'=>null]);
        \App\Models\StockMovement::query()->delete();
        $this->post(route('sale.sales.cancel',$sale))->assertSessionHasErrors('sale');
        $this->assertEquals(88,$this->stock());
        $this->assertSame('pending',$sale->fresh()->status);
    }

    public function test_existing_stock_blocks_unit_changes_but_allows_price_changes(): void
    {
        $data=$this->productData();
        $data['product_units'][0]['contains_quantity']=24;
        $this->put(route('master.products.update',$this->product),$data)->assertSessionHasErrors('product_units');
        $this->assertEquals(12,$this->pack->fresh()->conversion_to_base);
        $data=$this->productData();
        $data['product_units'][0]['sell_price']=25000;
        $this->put(route('master.products.update',$this->product),$data)->assertSessionHasNoErrors();
        $this->assertEquals(25000,$this->pack->fresh()->sell_price);
        $this->assertEquals(100,$this->stock());
    }

    public function test_used_units_cannot_be_removed_or_replaced_as_base(): void
    {
        $this->hold();
        $data=$this->productData();
        $data['product_units']=[$data['product_units'][1]];
        $data['base_unit_index']=$data['default_purchase_unit_index']=$data['default_sale_unit_index']=0;
        $this->put(route('master.products.update',$this->product),$data)->assertSessionHasErrors('product_units');
        $this->assertNotNull($this->pack->fresh());
        $data=$this->productData();
        $data['base_unit_index']=0;
        $data['product_units'][0]['contains_unit_index']='';
        $data['product_units'][1]['contains_unit_index']=0;
        $this->put(route('master.products.update',$this->product),$data)->assertSessionHasErrors('product_units');
        $this->assertTrue($this->base->fresh()->is_base_unit);
    }

    public function test_mixed_units_and_multiple_locations_restore_their_own_original_quantities(): void
    {
        $other=Location::create(['name'=>'Gudang']);
        Stock::create(['product_id'=>$this->product->id,'location_id'=>$other->id,'quantity'=>50]);
        $data=$this->data();
        $data['items'][0]['sources']=[['location_id'=>$this->location->id,'quantity'=>.25],['location_id'=>$other->id,'quantity'=>.75]];
        $data['items'][]=['product_id'=>$this->product->id,'product_unit_id'=>$this->base->id,'quantity'=>2,
            'unit_price'=>2000,'sources'=>[['location_id'=>$this->location->id,'quantity'=>2]]];
        $this->post(route('sale.sales.store'),$data)->assertRedirect(route('sale.sales.index'));
        $this->assertEquals(95,$this->stock());
        $this->assertEquals(41,Stock::where('location_id',$other->id)->value('quantity'));
        $this->forceChangedConversion();
        $this->post(route('sale.sales.cancel',Sale::first()))->assertRedirect();
        $this->assertEquals(100,$this->stock());
        $this->assertEquals(50,Stock::where('location_id',$other->id)->value('quantity'));
    }

    public function test_a_paid_sale_also_records_the_original_stock_quantity(): void
    {
        $data=$this->data();
        $data['sale_status']='paid';
        $data['amount_paid']=24000;
        $this->post(route('sale.sales.store'),$data)->assertRedirect(route('sale.sales.index'));
        $this->assertEquals(12,Sale::first()->items()->first()->sources()->first()->base_quantity);
    }

    public function test_a_saved_snapshot_survives_a_missing_product_unit(): void
    {
        $sale=$this->hold();
        $this->pack->delete();
        $this->product->update(['conversion_factor'=>24]);
        $this->post(route('sale.sales.cancel',$sale))->assertRedirect();
        $this->assertEquals(100,$this->stock());
    }

    public function test_new_units_can_be_added_without_changing_existing_units(): void
    {
        $data=$this->productData();
        $unit=Unit::create(['name'=>'Pack']);
        $data['product_units'][]=['unit_id'=>$unit->id,'contains_quantity'=>6,'contains_unit_index'=>1,'buy_price'=>6000,'sell_price'=>12000];
        $this->put(route('master.products.update',$this->product),$data)->assertSessionHasNoErrors();
        $this->assertEquals(12,$this->pack->fresh()->conversion_to_base);
        $this->assertDatabaseHas('product_units',['product_id'=>$this->product->id,'unit_id'=>$unit->id,'conversion_to_base'=>6]);
    }

    public function test_an_unverifiable_legacy_note_does_not_block_other_automatic_cancellations(): void
    {
        $old=$this->hold();
        $old->items()->first()->sources()->update(['base_quantity'=>null]);
        \App\Models\StockMovement::query()->delete();
        $this->post(route('sale.sales.store'),$this->data())->assertRedirect(route('sale.sales.index'));
        $new=Sale::latest('id')->first();
        $this->travelTo(Carbon::parse('2026-10-17 18:01:00','Asia/Jakarta'));
        $this->assertSame(1,app(\App\Services\HeldSaleService::class)->cancelOverdue());
        $this->assertSame('pending',$old->fresh()->status);
        $this->assertSame('cancelled_auto',$new->fresh()->status);
        $this->assertEquals(88,$this->stock());
    }
}
