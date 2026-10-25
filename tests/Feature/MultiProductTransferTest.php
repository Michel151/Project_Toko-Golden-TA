<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Stock;
use App\Models\StockTransfer;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MultiProductTransferTest extends TestCase
{
    use RefreshDatabase;

    private function transferData(): array
    {
        $this->actingAs(User::create([
            'name' => 'Owner', 'email' => 'transfer@example.com', 'password' => 'password', 'role' => 'owner',
        ]));
        $from = Location::create(['name' => 'Gudang']);
        $to = Location::create(['name' => 'Toko']);
        $category = Category::create(['name' => 'Barang']);
        $unit = Unit::create(['name' => 'Pcs']);
        $items = [];
        foreach (['Produk A', 'Produk B'] as $name) {
            $product = Product::create([
                'name' => $name, 'category_id' => $category->id, 'buy_unit_id' => $unit->id,
                'buy_price' => 1000, 'sell_price_wholesale' => 2000, 'is_active' => true,
            ]);
            $productUnit = ProductUnit::create([
                'product_id' => $product->id, 'unit_id' => $unit->id,
                'conversion_to_base' => 1, 'is_base_unit' => true,
                'buy_price' => 1000, 'sell_price' => 2000,
            ]);
            Stock::create(['product_id' => $product->id, 'location_id' => $from->id, 'quantity' => 20]);
            $items[] = ['product_id' => $product->id, 'product_unit_id' => $productUnit->id, 'quantity' => 3];
        }
        return ['from_location_id' => $from->id, 'to_location_id' => $to->id, 'transfer_date' => today()->toDateString(), 'items' => $items];
    }

    public function test_multiple_products_use_one_transfer_and_update_all_stocks(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-17 10:00:00','Asia/Jakarta'));
        $data = $this->transferData();
        $pack = Unit::create(['name' => 'Pack']);
        $packUnit = ProductUnit::create([
            'product_id' => $data['items'][0]['product_id'], 'unit_id' => $pack->id,
            'conversion_to_base' => 5, 'is_base_unit' => false, 'buy_price' => 5000, 'sell_price' => 10000,
        ]);
        $data['items'][0]['product_unit_id'] = $packUnit->id;
        $this->post(route('inventory.transfers.store'), $data)->assertSessionHasNoErrors()->assertRedirect(route('inventory.transfers.index'));
        $this->assertDatabaseCount('stock_transfers', 1);
        $this->assertDatabaseCount('stock_transfer_items', 2);
        $this->assertDatabaseCount('stock_movements', 4);
        $this->assertSame('TRF/2026/10/000001',StockTransfer::first()->transfer_number);
        $this->assertDatabaseHas('stock_movements',['reference_number'=>'TRF/2026/10/000001']);
        foreach ($data['items'] as $index => $item) {
            $quantity = $index === 0 ? 15 : 3;
            $this->assertDatabaseHas('stocks', ['product_id' => $item['product_id'], 'location_id' => $data['from_location_id'], 'quantity' => 20 - $quantity]);
            $this->assertDatabaseHas('stocks', ['product_id' => $item['product_id'], 'location_id' => $data['to_location_id'], 'quantity' => $quantity]);
        }
        $this->get(route('inventory.transfers.show', StockTransfer::first()))->assertOk()->assertSee('Produk A')->assertSee('Produk B');
        $this->get(route('inventory.transfers.index', ['product_id' => $data['items'][1]['product_id']]))->assertOk()->assertSee(StockTransfer::first()->transfer_number);
        $form = $this->get(route('inventory.transfers.create'))->assertOk()->assertSee('Tambah Barang');
        if (getenv('CAPTURE_TRANSFER_UI') === '1') {
            $directory = storage_path('framework/testing/transfer');
            if (!is_dir($directory)) mkdir($directory, 0777, true);
            file_put_contents($directory.'/form.html', $form->getContent());
        }
        $this->get(route('report.transfer'))->assertOk()->assertSee('Produk A')->assertSee('Produk B');
        $pdf = view('report.pdf.transfer', ['transfers' => StockTransfer::with('items.product')->get(), 'from' => today()->toDateString(), 'to' => today()->toDateString()])->render();
        $this->assertStringContainsString('Produk A', $pdf);
        $this->assertStringContainsString('Produk B', $pdf);
        $this->assertStringContainsString('TRF/2026/10/000001', $pdf);
    }

    public function test_transfer_numbers_increment_monthly_and_restart_without_renumbering_old_transfers(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-17 10:00:00','Asia/Jakarta'));
        $data=$this->transferData();
        foreach (['TRF/2026/10/000001','TRF/2026/10/000002'] as $number) {
            $this->post(route('inventory.transfers.store'),$data)->assertSessionHasNoErrors()->assertRedirect(route('inventory.transfers.index'));
            $this->assertSame($number,StockTransfer::latest('id')->first()->transfer_number);
        }
        $this->travelTo(\Carbon\Carbon::parse('2026-11-01 10:00:00','Asia/Jakarta'));
        $data['transfer_date']='2026-11-01';
        $this->post(route('inventory.transfers.store'),$data)->assertSessionHasNoErrors();
        $november=StockTransfer::latest('id')->first();
        $this->assertSame('TRF/2026/11/000001',$november->transfer_number);
        $this->assertSame('TRF/2026/11/000002',StockTransfer::generateTransferNumber());
        $this->assertSame('TRF/2027/01/000001',StockTransfer::generateTransferNumber(\Carbon\Carbon::parse('2027-01-01','Asia/Jakarta')));
        $this->assertSame('TRF/2026/10/000001',StockTransfer::oldest('id')->first()->transfer_number);
        $this->get(route('inventory.transfers.index'))->assertOk()->assertSee('TRF/2026/10/000001')->assertSee($november->transfer_number);
        $this->get(route('inventory.transfers.show',$november))->assertOk()->assertSee($november->transfer_number);
    }

    public function test_monthly_sequence_uses_the_highest_number_and_ignores_legacy_and_other_periods(): void
    {
        $data=$this->transferData();
        $item=$data['items'][0];
        foreach (['TRF/2026/10/000100','TRF/2026/10/000003','TRF-2026-000999','TRF/2026/09/000999','TRF/2025/10/000999'] as $number) {
            StockTransfer::create([
                'transfer_number'=>$number, 'product_id'=>$item['product_id'], 'product_unit_id'=>$item['product_unit_id'],
                'from_location_id'=>$data['from_location_id'], 'to_location_id'=>$data['to_location_id'],
                'quantity'=>1, 'transfer_date'=>'2026-10-17', 'created_by'=>auth()->id(),
            ]);
        }
        $this->assertSame('TRF/2026/10/000101',StockTransfer::generateTransferNumber(\Carbon\Carbon::parse('2026-10-17','Asia/Jakarta')));
        $this->assertSame('TRF/2026/12/000001',StockTransfer::generateTransferNumber(\Carbon\Carbon::parse('2026-12-01','Asia/Jakarta')));
        $this->assertDatabaseHas('stock_transfers',['transfer_number'=>'TRF-2026-000999']);
    }

    public function test_transfer_number_uses_jakarta_issue_time_without_mutating_it(): void
    {
        $issuedAt=\Carbon\Carbon::parse('2026-12-31 17:01:00','UTC');
        $this->assertSame('TRF/2027/01/000001',StockTransfer::generateTransferNumber($issuedAt));
        $this->assertSame('2026-12-31 17:01:00 UTC',$issuedAt->format('Y-m-d H:i:s T'));
    }

    public function test_insufficient_stock_rolls_back_the_entire_transfer(): void
    {
        $data = $this->transferData();
        $data['items'][1]['quantity'] = 21;
        $this->post(route('inventory.transfers.store'), $data)->assertSessionHasErrors('items.1.quantity');
        $this->assertDatabaseCount('stock_transfers', 0);
        $this->assertDatabaseCount('stock_transfer_items', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        foreach ($data['items'] as $item) {
            $this->assertDatabaseHas('stocks', ['product_id' => $item['product_id'], 'location_id' => $data['from_location_id'], 'quantity' => 20]);
            $this->assertDatabaseMissing('stocks', ['product_id' => $item['product_id'], 'location_id' => $data['to_location_id']]);
        }
    }

    public function test_units_must_belong_to_the_product_and_products_cannot_repeat(): void
    {
        $data = $this->transferData();
        $data['items'][1]['product_unit_id'] = $data['items'][0]['product_unit_id'];
        $this->post(route('inventory.transfers.store'), $data)->assertSessionHasErrors('items.1.product_unit_id');
        $data['items'][1] = $data['items'][0];
        $this->post(route('inventory.transfers.store'), $data)->assertSessionHasErrors('items.1.product_id');
        $this->assertDatabaseCount('stock_transfers', 0);
    }

    public function test_locations_are_document_level_and_nested_overrides_are_rejected(): void
    {
        $data=$this->transferData();
        $other=Location::create(['name'=>'Lokasi Lain']);
        $data['items'][0]['from_location_id']=$other->id;
        $data['items'][1]['to_location_id']=$other->id;
        $this->post(route('inventory.transfers.store'),$data)
            ->assertSessionHasErrors(['items.0.from_location_id','items.1.to_location_id']);
        $this->assertDatabaseCount('stock_transfers',0);
        $this->assertDatabaseCount('stock_movements',0);
        unset($data['items'][0]['from_location_id'],$data['items'][1]['to_location_id']);
        $data['to_location_id']=$data['from_location_id'];
        $this->post(route('inventory.transfers.store'),$data)->assertSessionHasErrors('to_location_id');
        $html=$this->get(route('inventory.transfers.create'))->assertOk()
            ->assertSeeInOrder(['Informasi Transfer','Lokasi Asal','Lokasi Tujuan','Daftar Barang'])->getContent();
        $this->assertSame(1,substr_count($html,'name="from_location_id"'));
        $this->assertSame(1,substr_count($html,'name="to_location_id"'));
    }

    public function test_availability_returns_current_stock_for_one_source_and_excludes_expired_batches(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-10-16 12:00:00');
        try {
            $data=$this->transferData();
            $first=Product::findOrFail($data['items'][0]['product_id']);
            $first->update(['is_expirable'=>true]);
            foreach([['2026-10-15',10],['2026-10-16',2],['2026-11-01',3],[null,1]] as [$date,$qty]) {
                \App\Models\StockBatch::create(['product_id'=>$first->id,'location_id'=>$data['from_location_id'],
                    'received_date'=>'2026-09-01','expired_date'=>$date,'quantity_initial'=>$qty,'quantity_remaining'=>$qty]);
            }
            $params=['from_location_id'=>$data['from_location_id'],'product_ids'=>array_column($data['items'],'product_id')];
            $response=$this->getJson(route('inventory.transfers.availability',$params))->assertOk()
                ->assertJsonPath('from_location_id',$data['from_location_id']);
            $this->assertEquals(6,$response->json('products.0.sellable_quantity'));
            $this->assertEquals(20,$response->json('products.1.sellable_quantity'));
            $this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
            Stock::where('product_id',$data['items'][1]['product_id'])->update(['quantity'=>4]);
            $this->getJson(route('inventory.transfers.availability',$params))->assertOk()->assertJsonPath('products.1.sellable_quantity',4);
            $params['from_location_id']=$data['to_location_id'];
            $this->getJson(route('inventory.transfers.availability',$params))->assertOk()->assertJsonPath('products.0.sellable_quantity',0)->assertJsonPath('products.1.sellable_quantity',0);
            $this->assertDatabaseCount('stock_transfers',0);
            $this->assertDatabaseCount('stock_movements',0);
        } finally { \Illuminate\Support\Carbon::setTestNow(); }
    }

    public function test_availability_validates_source_products_and_create_permission(): void
    {
        $data=$this->transferData();
        $params=['from_location_id'=>$data['from_location_id'],'product_ids'=>array_column($data['items'],'product_id')];
        $this->getJson(route('inventory.transfers.availability',['from_location_id'=>99999,'product_ids'=>[99999]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['from_location_id','product_ids.0']);
        $cashier=User::create(['name'=>'Kasir','email'=>'transfer-cashier@example.com','password'=>'password','role'=>'kasir']);
        $this->actingAs($cashier)->getJson(route('inventory.transfers.availability',$params))->assertForbidden();
        $warehouse=User::create(['name'=>'Gudang','email'=>'transfer-warehouse@example.com','password'=>'password','role'=>'staf_gudang']);
        $this->actingAs($warehouse)->getJson(route('inventory.transfers.availability',$params))->assertOk();
        DB::table('role_permissions')->where('role','staf_gudang')->where('permission','inventory.transfers.create')->delete();
        $this->getJson(route('inventory.transfers.availability',$params))->assertForbidden();
    }
}
