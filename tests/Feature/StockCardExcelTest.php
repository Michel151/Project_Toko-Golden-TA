<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, ProductUnit, Stock, StockMovement, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class StockCardExcelTest extends TestCase
{
    use RefreshDatabase;

    public function test_excel_exports_all_filtered_movements_with_same_running_balances_and_multi_units(): void
    {
        $owner = User::create(['name'=>'Owner','email'=>'stock-excel@test.com','password'=>'password','role'=>'owner']);
        $this->actingAs($owner);
        $pcs = Unit::create(['name'=>'Pcs']);
        $box = Unit::create(['name'=>'Dus']);
        $product = Product::create(['name'=>'Produk Uji', 'category_id'=>Category::create(['name'=>'Minuman'])->id,
            'buy_unit_id'=>$box->id, 'buy_price'=>12000, 'sell_price_wholesale'=>15000]);
        ProductUnit::create(['product_id'=>$product->id,'unit_id'=>$pcs->id,'conversion_to_base'=>1,'is_base_unit'=>true]);
        ProductUnit::create(['product_id'=>$product->id,'unit_id'=>$box->id,'conversion_to_base'=>12,'is_default_sale'=>true]);
        $location = Location::create(['name'=>'Gudang 1']);
        $other = Location::create(['name'=>'Gudang 2']);
        Stock::create(['product_id'=>$product->id,'location_id'=>$location->id,'quantity'=>512]);
        for ($i=0; $i<27; $i++) {
            StockMovement::create(['product_id'=>$product->id,'location_id'=>$location->id,
                'movement_date'=>$i===26 ? '2026-11-06' : '2026-11-02', 'movement_type'=>$i===25 ? 'sale' : 'purchase',
                'quantity'=>$i===25 ? -1 : 12, 'balance_before'=>0,'balance_after'=>0,
                'reference_number'=>'REF-'.$i, 'created_by'=>$owner->id]);
        }
        StockMovement::create(['product_id'=>$product->id,'location_id'=>$other->id,'movement_date'=>'2026-11-02',
            'movement_type'=>'purchase','quantity'=>10,'balance_before'=>0,'balance_after'=>10,'reference_number'=>'EXCLUDED','created_by'=>$owner->id]);
        $filters = ['product'=>$product->id,'from'=>'2026-11-01','to'=>'2026-11-05','location_id'=>$location->id,'page'=>2];
        $page = $this->get(route('inventory.stock-card.show',$filters))->assertOk()
            ->assertSee(e(route('inventory.stock-card.excel',array_diff_key($filters,['page'=>true]))),false);
        $this->assertEquals(201,$page->viewData('openingBalance'));
        $this->assertEquals(500,$page->viewData('closingBalance'));
        $this->assertCount(1,$page->viewData('movements'));
        $this->assertSame('REF-0', $page->viewData('movements')->first()->reference_number);
        $this->assertEquals(213, $page->viewData('movements')->first()->card_balance);
        $firstPage = $this->get(route('inventory.stock-card.show', array_replace($filters, ['page' => 1])))->assertOk();
        $movements = $firstPage->viewData('movements');
        $this->assertSame('REF-25', $movements->first()->reference_number);
        $this->assertEquals(500, $movements->first()->card_balance);
        $this->assertSame('REF-1', $movements->last()->reference_number);
        $this->assertEquals(225, $movements->last()->card_balance);
        $response = $this->get(route('inventory.stock-card.excel',$filters))->assertOk();
        $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $sheet = $book->getActiveSheet();
        $this->assertTrue($sheet->getShowGridlines());
        $this->assertSame('Petugas', $sheet->getCell('E4')->getValue());
        $this->assertSame('Owner', $sheet->getCell('E5')->getValue());
        $this->assertSame('Kartu Stok Barang',$sheet->getCell('A1')->getValue());
        $this->assertSame('center',$sheet->getStyle('A1')->getAlignment()->getHorizontal());
        $this->assertStringContainsString('Lokasi: Gudang 1',$sheet->getCell('A3')->getValue());
        $this->assertStringContainsString('Saldo Awal: 16 Dus 9 Pcs',$sheet->getCell('A3')->getValue());
        $this->assertSame('REF-0',$sheet->getCell('B5')->getValue());
        $this->assertSame('1 Dus',$sheet->getCell('F5')->getValue());
        $this->assertSame('17 Dus 9 Pcs',$sheet->getCell('H5')->getValue());
        $this->assertSame('REF-25',$sheet->getCell('B30')->getValue());
        $this->assertSame('1 Pcs',$sheet->getCell('G30')->getValue());
        $this->assertSame('41 Dus 8 Pcs',$sheet->getCell('H30')->getValue());
        $this->assertSame('TOTAL',$sheet->getCell('A31')->getValue());
        $this->assertSame('25 Dus',$sheet->getCell('F31')->getValue());
        $this->assertSame('41 Dus 8 Pcs',$sheet->getCell('H31')->getValue());
        $this->assertSame(31,$sheet->getHighestRow());
        $book->disconnectWorksheets();
        $empty = $this->get(route('inventory.stock-card.excel',array_replace($filters,['from'=>'2026-10-01','to'=>'2026-10-02'])))->assertOk();
        $book = IOFactory::load($empty->baseResponse->getFile()->getPathname());
        $this->assertSame('Tidak ada mutasi sesuai filter.',$book->getActiveSheet()->getCell('A5')->getValue());
        $book->disconnectWorksheets();
        $this->getJson(route('inventory.stock-card.excel',array_replace($filters,['location_id'=>999999])))->assertUnprocessable();
        $this->getJson(route('inventory.stock-card.excel',array_replace($filters,['from'=>'2026-11-10'])))->assertUnprocessable();
        $staff = User::create(['name'=>'Tanpa Akses','email'=>'no-stock-excel@test.com','password'=>'password','role'=>'staf_gudang']);
        DB::table('role_permissions')->where('role','staf_gudang')->where('permission','inventory.stock.view')->delete();
        $this->actingAs($staff)->get(route('inventory.stock-card.excel',$filters))->assertForbidden();
    }
}
