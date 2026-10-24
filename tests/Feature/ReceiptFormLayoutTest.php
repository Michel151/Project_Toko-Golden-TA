<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, ProductUnit, PurchaseOrder, Supplier, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Support\MessageBag;
use Tests\TestCase;

class ReceiptFormLayoutTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseOrder $order;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Jakarta')->setDate(2026,10,21)->setTime(10,0));
        $owner = User::create(['name'=>'Owner','email'=>'receipt-layout@test.com','password'=>'password','role'=>'owner']);
        $this->actingAs($owner);
        $this->location = Location::create(['name'=>'Toko']); Location::create(['name'=>'Gudang 1']);
        $category = Category::create(['name'=>'Barang Uji']); $unit = Unit::create(['name'=>'Dus']);
        $supplier = Supplier::create(['name'=>'PT Supplier Uji']);
        $this->order = PurchaseOrder::create(['po_number'=>'PO/2026/10/000001','supplier_id'=>$supplier->id,'order_date'=>'2026-10-21','created_by'=>$owner->id,'status'=>'pending']);
        $names = ['Air Mineral 600ml','Biskuit 120g','Buku Tulis 38 Lembar','Deterjen 800g'];
        for ($i=0; $i<30; $i++) {
            $product = Product::create(['name'=>'[UJI] '.($names[$i] ?? 'Barang '.str_pad($i+1,2,'0',STR_PAD_LEFT)),
                'barcode'=>'RECEIPT'.($i+1),'category_id'=>$category->id,'buy_unit_id'=>$unit->id,'buy_price'=>10000,'sell_price_wholesale'=>12000,'is_expirable'=>$i%4<2,'is_active'=>true]);
            $pu = ProductUnit::create(['product_id'=>$product->id,'unit_id'=>$unit->id,'contains_quantity'=>1,'conversion_to_base'=>1,
                'buy_price'=>10000,'sell_price'=>12000,'is_base_unit'=>true,'is_default_purchase'=>true,'is_default_sale'=>true]);
            $this->order->items()->create(['product_id'=>$product->id,'product_unit_id'=>$pu->id,'quantity_ordered'=>10,'buy_price'=>10000]);
        }
    }

    private function page()
    {
        return $this->get(route('purchase.receipts.create',['order_id'=>$this->order->id]))->assertOk();
    }

    private function capture(string $name, $response): void
    {
        if (getenv('CAPTURE_RECEIVING_UI') !== '1') return;
        $dir = storage_path('framework/testing/receiving'); if (!is_dir($dir)) mkdir($dir,0777,true);
        file_put_contents($dir.'/'.$name.'.html',$response->getContent());
    }

    public function test_many_items_have_collapsed_details_search_and_stable_submission_fields(): void
    {
        $response = $this->page()->assertSee('Cari Barang')->assertSee('Status Pemeriksaan')->assertSee('Siap Disimpan');
        $dom = new \DOMDocument; @$dom->loadHTML($response->getContent()); $xpath = new \DOMXPath($dom);
        $this->assertCount(30,$xpath->query('//tr[contains(@class,"inspection-row")]'));
        $this->assertCount(30,$xpath->query('//tr[contains(@class,"receiving-details") and @hidden]'));
        $this->assertCount(30,$xpath->query('//button[contains(@class,"receiving-toggle") and @aria-expanded="false"]'));
        $this->assertCount(1,$xpath->query('//input[@name="items[0][purchase_order_item_id]"]'));
        $this->assertCount(1,$xpath->query('//select[@name="items[2][allocations][0][location_id]"]'));
        $this->assertCount(1,$xpath->query('//input[@name="items[0][batches][0][expired_date]"]'));
        $this->capture('blank',$response);
        $this->withSession(['_old_input'=>['items'=>[0=>['quantity_received'=>10,'batches'=>[
            ['quantity'=>6,'location_id'=>$this->location->id,'expired_date'=>'2027-01-01'],
            ['quantity'=>4,'location_id'=>$this->location->id,'expired_date'=>'2027-02-01'],
        ]]]]]);
        $filled = $this->page()->assertSee('2027-02-01');
        $this->capture('filled',$filled);
    }

    public function test_validation_errors_mark_the_affected_detail_to_reopen_and_keep_old_values(): void
    {
        $errors = new ViewErrorBag; $errors->put('default',new MessageBag(['items.0.batches.0.expired_date'=>'Isi kedaluwarsa']));
        $this->withSession(['errors'=>$errors,'_old_input'=>['items'=>[0=>['quantity_received'=>3]]]]);
        $this->page()->assertSee('data-start-open="true"',false)->assertSee('value="3"',false);
    }

    public function test_receiving_payload_and_cost_calculation_still_accept_split_expiry_rows(): void
    {
        $item = $this->order->items()->first();
        $this->post(route('purchase.receipts.store'),['purchase_order_id'=>$this->order->id,'receipt_date'=>'2026-10-21','items'=>[
            ['purchase_order_item_id'=>$item->id,'product_id'=>$item->product_id,'product_unit_id'=>$item->product_unit_id,
             'quantity_received'=>3,'quantity_damaged'=>0,'quantity_wrong'=>0,'quantity_missing'=>0,'batches'=>[
                ['quantity'=>2,'location_id'=>$this->location->id,'expired_date'=>'2027-01-01'],
                ['quantity'=>1,'location_id'=>$this->location->id,'expired_date'=>'2027-02-01'],
             ]],
        ]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stocks',['product_id'=>$item->product_id,'quantity'=>3]);
        $this->assertDatabaseHas('inventory_cost_balances',['product_id'=>$item->product_id,'inventory_value'=>30000]);
        $this->assertDatabaseCount('stock_batches',2);
    }
}
