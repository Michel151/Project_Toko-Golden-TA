<?php

namespace Tests\Feature;

use App\Exports\ProductImportTrialTemplateExport;
use App\Models\{Category,Location,Product,ProductImportRun,Stock,StockBatch,StockMovement,Unit,User};
use App\Services\MultiUnitProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiUnitProductImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner=User::create(['name'=>'Owner','email'=>'multi-import@example.com','password'=>'password','role'=>'owner']);
        $this->actingAs($this->owner);
    }

    private function sheets(): array
    {
        $sheets=[];
        foreach((new ProductImportTrialTemplateExport)->sheets() as $sheet) $sheets[$sheet->title()]=$sheet->array();
        return $sheets;
    }

    private function stage(?array $sheets=null): ProductImportRun
    {
        return app(MultiUnitProductImportService::class)->stage($sheets ?? $this->sheets(),$this->owner->id);
    }

    public function test_confirmed_import_creates_units_chain_prices_and_initial_stock_exactly_once(): void
    {
        $run=$this->stage();
        $this->get(route('master.products.import',['preview'=>$run->token]))->assertOk()->assertSee('Simpan Hasil Impor');
        $this->assertDatabaseCount('products',0);
        $this->assertStringNotContainsString('Rokok Contoh',$run->getRawOriginal('payload'));
        $this->post(route('master.products.import.store'),['token'=>$run->token,'confirmed'=>1])->assertRedirect(route('master.products.index'));
        $product=Product::where('barcode','0012345678901')->with('productUnits.unit')->firstOrFail();
        $units=$product->productUnits->keyBy(fn($unit)=>$unit->unit->name);
        $this->assertEquals(500,$units['Dus']->conversion_to_base);
        $this->assertSame($units['Bal']->id,$units['Dus']->contains_unit_id);
        $this->assertEquals(5,$units['Dus']->contains_quantity);
        $this->assertTrue($units['Dus']->is_default_purchase);
        $this->assertTrue($units['Bks']->is_default_sale);
        $this->assertTrue($units['Bks']->is_base_unit);
        $this->assertNull($units['Bks']->contains_unit_id);
        $this->assertEquals(450000,$units['Dus']->sell_price);
        $toko=Location::where('name','Toko')->firstOrFail();
        $this->assertEquals(1003,Stock::where('product_id',$product->id)->where('location_id',$toko->id)->value('quantity'));
        $batch=StockBatch::firstOrFail();
        $this->assertNull($batch->batch_code);$this->assertEquals(51,$batch->quantity_remaining);
        $this->assertDatabaseCount('products',2);$this->assertDatabaseCount('product_units',6);$this->assertDatabaseCount('stocks',4);
        $this->assertDatabaseCount('stock_movements',4);
        $this->assertSame(ProductImportRun::class,StockMovement::first()->reference_type);
        $this->assertSame($run->id,(int)StockMovement::first()->reference_id);
        $this->assertSame('completed',$run->fresh()->status);$this->assertNull($run->fresh()->payload);
        $this->post(route('master.products.import.store'),['token'=>$run->token,'confirmed'=>1])->assertRedirect(route('master.products.index'));
        $this->assertDatabaseCount('products',2);$this->assertDatabaseCount('stocks',4);$this->assertDatabaseCount('stock_batches',1);$this->assertDatabaseCount('stock_movements',4);
    }

    public function test_missing_confirmation_expiry_and_another_users_token_are_rejected(): void
    {
        $run=$this->stage();
        $this->post(route('master.products.import.store'),['token'=>$run->token])->assertSessionHasErrors('confirmed');
        $run->update(['expires_at'=>now()->subMinute()]);
        $this->post(route('master.products.import.store'),['token'=>$run->token,'confirmed'=>1])->assertSessionHasErrors('file');
        $other=User::create(['name'=>'Other Owner','email'=>'other-multi-import@example.com','password'=>'password','role'=>'owner']);
        $this->actingAs($other)->post(route('master.products.import.store'),['token'=>$run->token,'confirmed'=>1])->assertNotFound();
        $this->get(route('master.products.import',['preview'=>$run->token]))->assertNotFound();
        $this->assertDatabaseCount('products',0);
    }

    public function test_multiple_expiry_dates_without_codes_are_previewed_and_saved_separately(): void
    {
        $sheets=$this->sheets();
        $expired=now('Asia/Jakarta')->subDay()->toDateString();
        $sheets['Stok Awal'][]=['DEMO002','Toko',1,'Dus',$expired];
        $sheets['Stok Awal'][]=['DEMO002','Gudang 1',2,'Pcs',$expired];
        $run=$this->stage($sheets);
        $response=$this->get(route('master.products.import',['preview'=>$run->token]))->assertOk()
            ->assertSee('Kedaluwarsa')->assertDontSee('kode_batch')->assertDontSee('Batch / Kedaluwarsa');
        if (getenv('CAPTURE_IMPORT_UI') === '1') {
            $directory=storage_path('framework/testing/import-ui');
            if (!is_dir($directory)) mkdir($directory,0777,true);
            file_put_contents($directory.'/preview.html',$response->getContent());
        }
        $this->assertDatabaseCount('products',0);
        $this->post(route('master.products.import.store'),['token'=>$run->token,'confirmed'=>1])
            ->assertRedirect(route('master.products.index'));
        $product=Product::where('name','Biskuit Contoh')->firstOrFail();
        $this->assertEquals(77,$product->stocks()->sum('quantity'));
        $this->assertEquals(51,$product->sellableStockBatches()->sum('quantity_remaining'));
        $this->assertSame(3,$product->stockBatches()->count());
        $this->assertFalse($product->stockBatches()->whereNotNull('batch_code')->exists());
        $toko=Location::where('name','Toko')->firstOrFail();
        $this->assertEquals(24,$product->stockBatches()->where('location_id',$toko->id)->whereDate('expired_date',$expired)->value('quantity_remaining'));
        $this->post(route('master.products.import.store'),['token'=>$run->token,'confirmed'=>1])->assertRedirect();
        $this->assertSame(3,$product->stockBatches()->count());
        $this->assertEquals(77,$product->stocks()->sum('quantity'));
    }

    public function test_expiry_date_remains_required_only_for_expirable_products(): void
    {
        $sheets=$this->sheets();
        $sheets['Stok Awal'][5][4]='';
        $plan=app(MultiUnitProductImportService::class)->prepare($sheets);
        $this->assertStringContainsString('wajib memiliki tanggal_kedaluwarsa',json_encode($plan['errors']));
        $sheets=$this->sheets();
        $sheets['Stok Awal'][1][4]='2027-01-01';
        $this->assertStringContainsString('kedaluwarsa barang adalah tidak',json_encode(app(MultiUnitProductImportService::class)->prepare($sheets)['errors']));
        $sheets=$this->sheets();
        $sheets['Stok Awal'][5][4]='2026-02-31';
        $this->assertStringContainsString('yang valid',json_encode(app(MultiUnitProductImportService::class)->prepare($sheets)['errors']));
        $this->assertDatabaseCount('products',0);
    }

    public function test_source_is_server_side_and_invalid_later_data_does_not_partially_import(): void
    {
        $sheets=$this->sheets();$sheets['Stok Awal'][6][2]=-1;
        $run=$this->stage($sheets);
        $this->post(route('master.products.import.store'),['token'=>$run->token,'confirmed'=>1,'products'=>[['name'=>'Forged']]])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('products',0);$this->assertDatabaseCount('stocks',0);$this->assertDatabaseCount('categories',0);
        $valid=$this->stage();
        $this->post(route('master.products.import.store'),['token'=>$valid->token,'confirmed'=>1,'products'=>[['name'=>'Forged']]])->assertRedirect(route('master.products.index'));
        $this->assertDatabaseMissing('products',['name'=>'Forged']);
    }

    public function test_existing_product_created_after_preview_is_not_overwritten(): void
    {
        $run=$this->stage();
        $category=Category::create(['name'=>'Existing']);$unit=Unit::create(['name'=>'Existing']);
        $product=Product::create(['name'=>'Existing Product','barcode'=>'0012345678901','category_id'=>$category->id,'buy_unit_id'=>$unit->id,'buy_price'=>123,'sell_price_wholesale'=>456]);
        $this->post(route('master.products.import.store'),['token'=>$run->token,'confirmed'=>1])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('products',1);$this->assertEquals(123,$product->fresh()->buy_price);$this->assertDatabaseCount('stocks',0);
    }

    public function test_database_failure_rolls_back_created_products_references_and_stock(): void
    {
        Location::create(['name'=>'Toko']);Location::create(['name'=>'toko']);
        $run=$this->stage();
        $this->post(route('master.products.import.store'),['token'=>$run->token,'confirmed'=>1])->assertSessionHasErrors('file');
        foreach(['products','product_units','categories','units','stocks','stock_batches','stock_movements'] as $table) $this->assertDatabaseCount($table,0);
        $this->assertDatabaseCount('locations',2);$this->assertSame('pending',$run->fresh()->status);
    }

    public function test_all_rows_are_saved_beyond_the_preview_display_limit(): void
    {
        $sample=$this->sheets();$sheets=array_map(fn($rows)=>[$rows[0]],$sample);
        for($i=0;$i<105;$i++){
            $sheets['Barang'][]=['P'.$i,'Barang '.$i,'BC'.$i,'Kategori','Pcs','Pcs',0,'tidak'];
            $sheets['Satuan'][]=['P'.$i,'Pcs',1,'Pcs',500,1000];
            $sheets['Stok Awal'][]=['P'.$i,'Toko',1,'Pcs',''];
        }
        $run=$this->stage($sheets);
        $this->post(route('master.products.import.store'),['token'=>$run->token,'confirmed'=>1])->assertRedirect(route('master.products.index'));
        $this->assertDatabaseCount('products',105);$this->assertDatabaseCount('stocks',105);
    }

    public function test_expired_batches_are_saved_physically_and_batch_codes_are_preserved(): void
    {
        $sheets=$this->sheets();
        $sheets['Stok Awal'][0]=['kode_barang','lokasi','jumlah','satuan','kode_batch','tanggal_kedaluwarsa'];
        foreach ($sheets['Stok Awal'] as $index => &$row) {
            if ($index > 0) array_splice($row,4,0,['']);
        }
        unset($row);
        $sheets['Stok Awal'][]=['DEMO002','Toko',1,'Dus','OLD-BATCH',now('Asia/Jakarta')->subDay()->toDateString()];
        $run=$this->stage($sheets);
        $this->post(route('master.products.import.store'),['token'=>$run->token,'confirmed'=>1])->assertRedirect(route('master.products.index'));
        $product=Product::where('name','Biskuit Contoh')->firstOrFail();
        $this->assertEquals(75,$product->stocks()->sum('quantity'));
        $this->assertEquals(51,$product->sellableStockBatches()->sum('quantity_remaining'));
        $this->assertDatabaseHas('stock_batches',['product_id'=>$product->id,'batch_code'=>'OLD-BATCH','quantity_remaining'=>24]);
    }
}
