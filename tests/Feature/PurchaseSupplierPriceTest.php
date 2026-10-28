<?php

namespace Tests\Feature;

use App\Models\{Category, InventoryCostBalance, Location, Product, ProductUnit, PurchaseOrder, PurchaseReceipt, Sale, SaleItem, Supplier, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseSupplierPriceTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private ProductUnit $box;
    private ProductUnit $bottle;
    private Supplier $one;
    private Supplier $two;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-28 10:00:00', 'Asia/Jakarta'));
        $this->actingAs(User::create(['name'=>'Owner Harga','email'=>'purchase-price@test.com','password'=>'password','role'=>'owner']));
        DB::table('inventory_cost_settings')->where('id',1)->update(['automatic_from_zero'=>true]);
        $category = Category::create(['name'=>'Minuman']);
        $dus = Unit::create(['name'=>'Dus']);
        $pack = Unit::create(['name'=>'Pak']);
        $botol = Unit::create(['name'=>'Botol']);
        $this->product = Product::create(['name'=>'Teh Harga Supplier','barcode'=>'PRICE001','category_id'=>$category->id,
            'buy_unit_id'=>$dus->id,'buy_price'=>66000,'retail_unit_id'=>$botol->id,'can_retail'=>true,'conversion_factor'=>24,'sell_price_wholesale'=>80000,'sell_price_retail'=>4000,'is_active'=>true]);
        $this->box = ProductUnit::create(['product_id'=>$this->product->id,'unit_id'=>$dus->id,'conversion_to_base'=>24,'buy_price'=>66000,'sell_price'=>80000,'is_default_purchase'=>true]);
        ProductUnit::create(['product_id'=>$this->product->id,'unit_id'=>$pack->id,'conversion_to_base'=>6,'buy_price'=>16500,'sell_price'=>20000]);
        $this->bottle = ProductUnit::create(['product_id'=>$this->product->id,'unit_id'=>$botol->id,'conversion_to_base'=>1,'buy_price'=>2750,'sell_price'=>4000,'is_base_unit'=>true,'is_default_sale'=>true]);
        $this->one = Supplier::create(['name'=>'Supplier 1']);
        $this->two = Supplier::create(['name'=>'Supplier 2']);
        $this->one->products()->attach($this->product->id,['buy_price'=>66000]);
        $this->two->products()->attach($this->product->id,['buy_price'=>65000]);
        $this->location = Location::create(['name'=>'Toko']);
    }

    private function capture(string $name, $response): void
    {
        if (getenv('CAPTURE_PURCHASE_PRICE_UI') !== '1') return;
        $dir = storage_path('framework/testing/purchase-prices');
        if (!is_dir($dir)) mkdir($dir,0777,true);
        file_put_contents($dir.'/'.$name.'.html',$response->getContent());
    }

    private function order(float $price, ?ProductUnit $unit = null, float $quantity = 2): PurchaseOrder
    {
        $order = PurchaseOrder::create(['po_number'=>'PO-PRICE-'.(PurchaseOrder::count()+1),'supplier_id'=>$this->two->id,'order_date'=>today(),'status'=>'pending','created_by'=>auth()->id()]);
        $order->items()->create(['product_id'=>$this->product->id,'product_unit_id'=>($unit ?? $this->box)->id,'quantity_ordered'=>$quantity,'buy_price'=>$price]);
        return $order;
    }

    private function receive(PurchaseOrder $order, float $quantity, ?string $token = null, string $date = '2026-10-28', array $extra = [])
    {
        $item = $order->items()->firstOrFail();
        return $this->post(route('purchase.receipts.store'), ['purchase_order_id'=>$order->id,'receipt_date'=>$date,'submission_token'=>$token,
            'items'=>[array_replace(['purchase_order_item_id'=>$item->id,'product_id'=>$this->product->id,'product_unit_id'=>$item->product_unit_id,
                'quantity_received'=>$quantity,'allocations'=>$quantity > 0 ? [['location_id'=>$this->location->id,'quantity'=>$quantity]] : []], $extra)]]);
    }

    public function test_both_po_data_sources_use_supplier_prices_with_conversions_and_explicit_zero(): void
    {
        $response = $this->get(route('purchase.orders.create'))->assertOk();
        $this->capture('create',$response);
        $json = $response->viewData('supplierProductsJson');
        $this->assertEquals(66000,$json[$this->one->id][0]['price']);
        $supplier = $json[$this->two->id][0];
        $this->assertEquals(65000,$supplier['price']);
        $this->assertSame('supplier',$supplier['price_source']);
        $this->assertEquals([65000,16250,2708.33],array_column($supplier['units'],'buy_price'));
        $this->get(route('purchase.orders.supplier-products',$this->two))->assertOk()->assertJsonPath('0.price',65000)
            ->assertJsonPath('0.units.0.buy_price',65000)->assertJsonPath('0.units.2.buy_price',2708.33);
        $this->two->products()->updateExistingPivot($this->product->id,['buy_price'=>0]);
        $zero = $this->get(route('purchase.orders.create'))->assertOk();
        $this->capture('zero',$zero);
        $this->assertEquals([0,0,0],array_column($zero->viewData('supplierProductsJson')[$this->two->id][0]['units'],'buy_price'));
    }

    public function test_po_search_assets_and_large_supplier_specific_product_catalog_are_available(): void
    {
        for ($i=0; $i<320; $i++) {
            $product = $this->product->replicate();
            $product->name = 'Barang Pencarian '.str_pad($i,3,'0',STR_PAD_LEFT);
            $product->barcode = 'FIND'.str_pad($i,5,'0',STR_PAD_LEFT);
            $product->save();
            foreach ([$this->box,$this->bottle] as $source) {
                $unit = $source->replicate();
                $unit->product_id = $product->id;
                $unit->save();
            }
            $this->two->products()->attach($product->id,['buy_price'=>65000]);
        }
        for ($i=0; $i<35; $i++) Supplier::create(['name'=>'Pemasok Pencarian '.str_pad($i,2,'0',STR_PAD_LEFT)]);
        $response = $this->get(route('purchase.orders.create'))->assertOk()
            ->assertSee('data-po-search-library',false)
            ->assertSee('data-po-search-styles',false)
            ->assertDontSee('<link href="'.asset('vendor/tom-select-2.4.3/tom-select.bootstrap5.min.css').'"',false)
            ->assertSee('.ts-wrapper',false)
            ->assertSee('data-po-search-controls',false);
        $data = $response->viewData('supplierProductsJson');
        $this->assertCount(321,$data[$this->two->id]);
        $this->assertCount(1,$data[$this->one->id]);
        $this->assertSame('FIND00319',collect($data[$this->two->id])->firstWhere('name','Barang Pencarian 319')['barcode']);
        $this->capture('search-many',$response);
    }

    public function test_storing_editing_and_deleting_pending_po_never_updates_master_or_supplier_prices(): void
    {
        $before = DB::table('product_units')->get()->toJson();
        $suppliers = DB::table('product_supplier')->get()->toJson();
        $payload = ['supplier_id'=>$this->two->id,'order_date'=>'2026-10-28','items'=>[['product_id'=>$this->product->id,
            'product_unit_id'=>$this->box->id,'quantity_ordered'=>2,'buy_price'=>'64000']]];
        $this->post(route('purchase.orders.store'),$payload)->assertSessionHasNoErrors();
        $order = PurchaseOrder::firstOrFail();
        $this->two->products()->updateExistingPivot($this->product->id,['buy_price'=>65000]);
        $edit = $this->get(route('purchase.orders.edit',$order))->assertOk();
        $this->capture('edit',$edit);
        $this->assertStringContainsString('value="64000.00"',$edit->getContent());
        $payload['items'][0]['buy_price'] = '63000';
        $this->put(route('purchase.orders.update',$order),$payload)->assertSessionHasNoErrors();
        $this->assertEquals(63000,$order->items()->first()->buy_price);
        $this->delete(route('purchase.orders.destroy',$order), ['reason'=>'Draf pengujian tidak dilanjutkan'])->assertSessionHasNoErrors();
        $this->assertSame($before,DB::table('product_units')->get()->toJson());
        $this->assertSame($suppliers,DB::table('product_supplier')->get()->toJson());
        $this->assertEquals(66000,$this->product->fresh()->buy_price);
    }

    public function test_receipts_update_all_master_units_but_preserve_historical_hpp_and_moving_average(): void
    {
        $old = $this->order(60000);
        $this->receive($old,2)->assertSessionHasNoErrors();
        $this->assertEquals(60000,$this->product->fresh()->buy_price);
        $this->assertEquals([60000,15000,2500],$this->product->productUnits()->orderByDesc('conversion_to_base')->pluck('buy_price')->map(fn ($price)=>(float)$price)->all());
        $sale = Sale::create(['sale_number'=>'INV-OLD-COST','sale_date'=>today(),'status'=>'paid','payment_method'=>'tunai','total_amount'=>5000,'created_by'=>auth()->id()]);
        SaleItem::create(['sale_id'=>$sale->id,'product_id'=>$this->product->id,'quantity'=>1,'unit_price'=>5000,'subtotal'=>5000,'cost_price'=>2500,'cost_subtotal'=>2500,'cost_method'=>'moving_average','sale_type'=>'ecer']);
        $history = DB::table('sale_items')->get()->toJson();
        $oldEntries = DB::table('inventory_cost_entries')->get()->toJson();
        $oldEntryIds = DB::table('inventory_cost_entries')->pluck('id')->all();
        $order = $this->order(3000,$this->bottle,6);
        $token = (string) \Illuminate\Support\Str::uuid();
        $this->receive($order,6,$token)->assertSessionHasNoErrors();
        $this->assertEquals(72000,$this->product->fresh()->buy_price);
        $this->assertEquals([72000,18000,3000],$this->product->productUnits()->orderByDesc('conversion_to_base')->pluck('buy_price')->map(fn ($price)=>(float)$price)->all());
        $balance = InventoryCostBalance::findOrFail($this->product->id);
        $this->assertEquals(54,$balance->quantity);
        $this->assertEquals(138000,$balance->inventory_value);
        $this->assertEqualsWithDelta(138000/54,(float)$balance->average_cost,0.000001);
        $this->assertSame($history,DB::table('sale_items')->get()->toJson());
        $this->assertSame($oldEntries,DB::table('inventory_cost_entries')->whereIn('id',$oldEntryIds)->get()->toJson());
        $this->assertEquals(60000,$old->items()->first()->buy_price);
        $count = DB::table('inventory_cost_entries')->count();
        $this->receive($order,6,$token)->assertSessionHasNoErrors();
        $this->assertSame($count,DB::table('inventory_cost_entries')->count());
        $this->assertEquals(72000,$this->product->fresh()->buy_price);
        $this->assertEquals(65000,$this->two->products()->first()->pivot->buy_price);
    }

    public function test_problem_only_and_failed_receipts_do_not_update_master_prices(): void
    {
        $order = $this->order(65000);
        $this->receive($order,0,null,'2026-10-28',['quantity_missing'=>2,'reason'=>'Belum datang'])->assertSessionHasNoErrors();
        $this->assertEquals(66000,$this->product->fresh()->buy_price);
        $this->assertEquals(66000,$this->box->fresh()->buy_price);
        $this->receive($this->order(65000),3)->assertSessionHasErrors();
        $this->assertEquals(66000,$this->product->fresh()->buy_price);
    }

    public function test_missing_supplier_price_falls_back_to_master_and_conversion_does_not_cascade_rounding(): void
    {
        $fallback = app(\App\Services\PurchasePriceService::class)->supplierProduct($this->product->fresh()->load(['buyUnit','productUnits.unit']));
        $this->assertSame('master',$fallback['price_source']);
        $this->assertEquals([66000,16500,2750],array_column($fallback['units'],'buy_price'));
        $this->receive($this->order(1000.25),1)->assertSessionHasNoErrors();
        $this->assertEquals([1000.25,250.06,41.68],$this->product->productUnits()->orderByDesc('conversion_to_base')->pluck('buy_price')->map(fn ($price)=>(float)$price)->all());
        $this->receive($this->order(0),1)->assertSessionHasErrors();
        $this->assertEquals(1000.25,$this->product->fresh()->buy_price);
        $this->assertEquals([1000.25,250.06,41.68],$this->product->productUnits()->orderByDesc('conversion_to_base')->pluck('buy_price')->map(fn ($price)=>(float)$price)->all());
    }

    public function test_middle_purchase_unit_and_receipt_rollback_preserve_consistency(): void
    {
        $pack = $this->product->productUnits()->where('conversion_to_base',6)->firstOrFail();
        $this->box->update(['is_default_purchase'=>false]);
        $pack->update(['is_default_purchase'=>true]);
        $this->product->update(['buy_unit_id'=>$pack->unit_id,'buy_price'=>16500]);
        $order = $this->order(3000,$this->bottle);
        $this->receive($order,1)->assertSessionHasNoErrors();
        $this->assertEquals(18000,$this->product->fresh()->buy_price);
        $this->assertEquals(72000,$this->box->fresh()->buy_price);
        $before = DB::table('product_units')->get()->toJson();
        $order = $this->order(2000,$this->bottle);
        $first = $order->items()->first();
        $second = $order->items()->create(['product_id'=>$this->product->id,'product_unit_id'=>$this->bottle->id,'quantity_ordered'=>1,'buy_price'=>1000]);
        $rows = [];
        foreach ([[$first,1],[$second,2]] as [$item,$qty]) $rows[] = ['purchase_order_item_id'=>$item->id,'product_id'=>$this->product->id,
            'product_unit_id'=>$item->product_unit_id,'quantity_received'=>$qty,'allocations'=>[['location_id'=>$this->location->id,'quantity'=>$qty]]];
        $this->post(route('purchase.receipts.store'),['purchase_order_id'=>$order->id,'receipt_date'=>'2026-10-28','items'=>$rows])->assertSessionHasErrors();
        $this->assertEquals(18000,$this->product->fresh()->buy_price);
        $this->assertSame($before,DB::table('product_units')->get()->toJson());
        $this->assertSame(0,$order->receipts()->count());
    }

    public function test_replacement_receipt_and_backdated_receipt_do_not_overwrite_latest_purchase_price(): void
    {
        DB::table('inventory_cost_settings')->where('id',1)->update(['automatic_from_zero'=>false]);
        $root = $this->order(60000);
        $this->receive($root,2)->assertSessionHasNoErrors();
        $replacement = $this->order(0);
        $replacement->update(['root_purchase_order_id'=>$root->id]);
        $this->receive($replacement,2)->assertSessionHasNoErrors();
        $this->assertEquals(60000,$this->product->fresh()->buy_price);
        $this->receive($this->order(50000),2,null,'2026-10-27')->assertSessionHasNoErrors();
        $this->assertEquals(60000,$this->product->fresh()->buy_price);
    }
}
