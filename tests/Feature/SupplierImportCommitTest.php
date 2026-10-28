<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, ProductUnit, Sale, SaleItem, Stock, Supplier, SupplierImportRun, Unit, User};
use App\Services\{SupplierImportService, SupplierImportTrialService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\{Spreadsheet, Writer\Xlsx};
use Tests\TestCase;

class SupplierImportCommitTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private Supplier $supplier;
    private ProductUnit $purchaseUnit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create(['name'=>'Owner Import','email'=>'supplier-commit@test.com','password'=>'password','role'=>'owner']));
        $category = Category::create(['name'=>'Minuman']);
        $pcs = Unit::create(['name'=>'Pcs']);
        $box = Unit::create(['name'=>'Dus']);
        $this->product = Product::create(['name'=>'Barang Lama','barcode'=>'001234','category_id'=>$category->id,
            'buy_unit_id'=>$box->id,'buy_price'=>24000,'sell_price_wholesale'=>30000,'is_active'=>true]);
        ProductUnit::create(['product_id'=>$this->product->id,'unit_id'=>$pcs->id,'conversion_to_base'=>1,'buy_price'=>1000,'sell_price'=>1250,'is_base_unit'=>true]);
        $this->purchaseUnit = ProductUnit::create(['product_id'=>$this->product->id,'unit_id'=>$box->id,'conversion_to_base'=>24,
            'buy_price'=>24000,'sell_price'=>30000,'is_default_purchase'=>true]);
        $this->supplier = Supplier::create(['name'=>'Supplier Lama','address'=>'Alamat Lama','phone'=>'081234']);
        $this->supplier->products()->attach($this->product->id, ['buy_price'=>24000]);
        $location = Location::create(['name'=>'Toko']);
        Stock::create(['product_id'=>$this->product->id,'location_id'=>$location->id,'quantity'=>50]);
        foreach (['legacy_master', 'moving_average'] as $method) {
            $sale = Sale::create(['sale_number'=>'INV-'.$method,'sale_date'=>today(),'status'=>'paid','payment_method'=>'tunai','total_amount'=>3000,'created_by'=>auth()->id()]);
            SaleItem::create(['sale_id'=>$sale->id,'product_id'=>$this->product->id,'quantity'=>1,'unit_price'=>3000,'subtotal'=>3000,
                'cost_price'=>1000,'cost_subtotal'=>1000,'cost_method'=>$method,'sale_type'=>'ecer']);
        }
    }

    private function sheets(): array
    {
        return ['Master Supplier'=>[SupplierImportTrialService::HEADINGS['Master Supplier'], ['NEW','Supplier Baru','Alamat Baru','081000',null]],
            'Supplier Barang'=>[SupplierImportTrialService::HEADINGS['Supplier Barang'], ['001234',$this->product->id,'NEW','Dus',48000],
                ['001234',$this->product->id,'SUP-'.$this->supplier->id,'Pcs',1500]]];
    }

    private function upload(array $sheets): UploadedFile
    {
        \PhpOffice\PhpSpreadsheet\Cell\Cell::setValueBinder(new \PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder);
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        foreach ($sheets as $name=>$rows) {
            $sheet = $book->createSheet();
            $sheet->setTitle($name);
            $sheet->fromArray($rows);
            if ($name === 'Supplier Barang') {
                foreach ($rows as $i=>$row) if ($i && filled($row[0])) $sheet->setCellValueExplicit('A'.($i+1), (string) $row[0], 's');
            }
        }
        $file = tempnam(sys_get_temp_dir(), 'supplier-commit-');
        try {
            (new Xlsx($book))->save($file);
            return UploadedFile::fake()->createWithContent('supplier.xlsx', file_get_contents($file));
        } finally {
            $book->disconnectWorksheets();
            unlink($file);
        }
    }

    private function preview(?array $sheets = null)
    {
        return $this->post(route('master.suppliers.import.preview'), ['file'=>$this->upload($sheets ?? $this->sheets())])->assertOk();
    }

    private function confirm(string $token)
    {
        return $this->post(route('master.suppliers.import.store'), ['token'=>$token,'confirmed'=>1]);
    }

    private function snapshot(): array
    {
        $tables = ['products','product_units','stocks','stock_batches','stock_movements','sales','sale_items',
            'inventory_cost_balances','inventory_cost_entries','purchase_orders','purchase_order_items'];
        return collect($tables)->mapWithKeys(fn ($table) => [$table=>DB::table($table)->get()->toJson()])->all();
    }

    private function capture(string $name, $response): void
    {
        if (getenv('CAPTURE_SUPPLIER_COMMIT_UI') !== '1') return;
        $dir = storage_path('framework/testing/supplier-commit');
        if (!is_dir($dir)) mkdir($dir,0777,true);
        file_put_contents($dir.'/'.$name.'.html', $response->getContent());
    }

    public function test_preview_commit_replay_and_reupload_preserve_all_old_data(): void
    {
        $before = $this->snapshot();
        $oldSupplier = $this->supplier->fresh()->toArray();
        $oldPivot = DB::table('product_supplier')->first();
        $response = $this->preview()->assertSee('Konfirmasi &amp; Simpan', false);
        $this->capture('valid', $response);
        $run = $response->viewData('run');
        $this->assertNotNull($run);
        $this->assertDatabaseCount('suppliers', 1);
        $this->assertDatabaseCount('product_supplier', 1);
        $this->assertStringNotContainsString('Supplier Baru', DB::table('supplier_import_runs')->value('payload'));
        $this->get(route('master.suppliers.import',['preview'=>$run->token]))->assertOk()->assertSee($run->token);
        $this->confirm($run->token)->assertRedirect(route('master.suppliers.index'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('suppliers', 2);
        $this->assertDatabaseCount('product_supplier', 2);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertNull($run->fresh()->payload);
        $this->assertEquals(['suppliers_created'=>1,'suppliers_reused'=>1,'links_created'=>1,'links_skipped'=>1], $run->fresh()->result);
        $new = Supplier::where('name','Supplier Baru')->firstOrFail();
        $this->assertEquals(48000, $new->products()->first()->pivot->buy_price);
        $this->confirm($run->token)->assertSessionHasNoErrors();
        $second = $this->preview()->viewData('run');
        $this->confirm($second->token)->assertSessionHasNoErrors();
        $this->assertEquals(['suppliers_created'=>0,'suppliers_reused'=>2,'links_created'=>0,'links_skipped'=>2], $second->fresh()->result);
        $this->assertDatabaseCount('suppliers', 2);
        $this->assertDatabaseCount('product_supplier', 2);
        $this->assertEquals($oldPivot, DB::table('product_supplier')->where('id',$oldPivot->id)->first());
        $this->assertSame($oldSupplier, $this->supplier->fresh()->toArray());
        $this->assertSame($before, $this->snapshot());
    }

    public function test_prices_are_converted_to_default_purchase_unit_without_updating_master_prices(): void
    {
        $data = $this->sheets();
        $data['Supplier Barang'] = [$data['Supplier Barang'][0], ['001234',$this->product->id,'NEW','Pcs',1500]];
        $response = $this->preview($data);
        $this->assertSame('36000.00', $response->viewData('preview')['supplier_links'][0]['purchase_price']);
        $this->confirm($response->viewData('run')->token)->assertSessionHasNoErrors();
        $this->assertEquals(36000, Supplier::where('name','Supplier Baru')->first()->products()->first()->pivot->buy_price);
        $this->assertEquals(24000, $this->product->fresh()->buy_price);
        $this->assertEquals(24000, $this->purchaseUnit->fresh()->buy_price);
    }

    public function test_confirmation_is_required_owned_expiring_and_ignores_client_payload(): void
    {
        $run = $this->preview()->viewData('run');
        $this->postJson(route('master.suppliers.import.store'), ['token'=>$run->token])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $other = User::create(['name'=>'Other','email'=>'other-import@test.com','password'=>'password','role'=>'owner']);
        $owner = auth()->user();
        $this->actingAs($other);
        $this->confirm($run->token)->assertNotFound();
        $this->get(route('master.suppliers.import',['preview'=>$run->token]))->assertNotFound();
        $this->actingAs($owner);
        $run->update(['expires_at'=>now()->subSecond()]);
        $this->confirm($run->token)->assertSessionHasErrors('file');
        $this->assertDatabaseCount('suppliers',1);
        $next = $this->preview()->viewData('run');
        $this->post(route('master.suppliers.import.store'), ['token'=>$next->token,'confirmed'=>1,'suppliers'=>[['name'=>'Injected']], 'price'=>0])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('suppliers',['name'=>'Injected']);
        $this->assertEquals(48000, Supplier::where('name','Supplier Baru')->first()->products()->first()->pivot->buy_price);
    }

    public function test_invalid_files_never_offer_confirmation_and_duplicate_names_are_not_created(): void
    {
        $data = $this->sheets();
        $data['Supplier Barang'][] = $data['Supplier Barang'][1];
        $response = $this->preview($data)->assertViewHas('run', null)->assertDontSee('supplierImportConfirmed');
        $this->capture('invalid', $response);
        $this->assertDatabaseCount('supplier_import_runs', 0);
        $data = $this->sheets();
        $data['Master Supplier'][1] = ['NEW','  supplier   lama  ','Different','0999',null];
        $data['Supplier Barang'] = [$data['Supplier Barang'][0], $data['Supplier Barang'][1]];
        $run = $this->preview($data)->viewData('run');
        $this->confirm($run->token)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('suppliers',1);
        $this->assertSame('Alamat Lama', $this->supplier->fresh()->address);
        Supplier::create(['name'=>' supplier lama ']);
        $this->preview($data)->assertViewHas('run',null)->assertSee('Nama supplier tidak unik');
    }

    public function test_revalidation_rejects_changed_products_and_units_before_any_write(): void
    {
        $run = $this->preview()->viewData('run');
        $this->purchaseUnit->update(['conversion_to_base'=>12]);
        $this->confirm($run->token)->assertSessionHasErrors('file');
        $this->assertDatabaseCount('suppliers',1);
        $this->assertDatabaseCount('product_supplier',1);
        $this->assertSame('pending',$run->fresh()->status);
        $this->purchaseUnit->update(['conversion_to_base'=>24]);
        $this->product->update(['is_active'=>false]);
        $this->confirm($run->token)->assertSessionHasErrors('file');
        $this->assertDatabaseCount('suppliers',1);
    }

    public function test_entire_import_rolls_back_on_failure(): void
    {
        $data = $this->sheets();
        $data['Master Supplier'][] = ['SECOND','Failure Supplier',null,null,null];
        $run = $this->preview($data)->viewData('run');
        $before = $this->snapshot();
        SupplierImportRun::updating(function ($run) { if ($run->status === 'completed') throw new \RuntimeException('Intentional import failure'); });
        try {
            app(SupplierImportService::class)->commit($run->token, auth()->id(), true);
            $this->fail('Expected transaction failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Intentional import failure',$exception->getMessage());
        } finally {
            SupplierImportRun::flushEventListeners();
        }
        $this->assertDatabaseCount('suppliers',1);
        $this->assertDatabaseCount('product_supplier',1);
        $this->assertSame('pending',$run->fresh()->status);
        $this->assertSame($before,$this->snapshot());
    }

    public function test_all_rows_beyond_preview_limit_are_saved_and_reimport_is_idempotent(): void
    {
        $data = ['Master Supplier'=>[SupplierImportTrialService::HEADINGS['Master Supplier']], 'Supplier Barang'=>[SupplierImportTrialService::HEADINGS['Supplier Barang']]];
        for ($i=1; $i<=205; $i++) {
            $data['Master Supplier'][] = ['CODE-'.$i,'Pemasok '.$i,null,null,null];
            $data['Supplier Barang'][] = ['001234',$this->product->id,'CODE-'.$i,'Dus',24000];
        }
        $response = $this->preview($data)->assertSee('205')->assertSee('Menampilkan 200 hubungan pertama.');
        $this->assertCount(200,$response->viewData('preview')['supplier_links']);
        $this->capture('many',$response);
        $this->confirm($response->viewData('run')->token)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('suppliers',206);
        $this->assertDatabaseCount('product_supplier',206);
        $this->confirm($this->preview($data)->viewData('run')->token)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('suppliers',206);
        $this->assertDatabaseCount('product_supplier',206);
    }

    public function test_two_prepared_uploads_cannot_duplicate_suppliers_or_relationships(): void
    {
        $one = $this->preview()->viewData('run');
        $two = $this->preview()->viewData('run');
        $this->assertNotSame($one->token, $two->token);
        $this->confirm($one->token)->assertSessionHasNoErrors();
        $this->confirm($two->token)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('suppliers',2);
        $this->assertDatabaseCount('product_supplier',2);
        $this->assertSame(0,$two->fresh()->result['suppliers_created']);
        $this->assertSame(0,$two->fresh()->result['links_created']);
    }

    public function test_existing_supplier_renamed_after_preview_requires_a_new_preview(): void
    {
        $data = $this->sheets();
        $data['Master Supplier'][1][1] = $this->supplier->name;
        $data['Supplier Barang'] = [$data['Supplier Barang'][0], $data['Supplier Barang'][1]];
        $run = $this->preview($data)->viewData('run');
        $this->supplier->update(['name'=>'Renamed Supplier']);
        $this->confirm($run->token)->assertSessionHasErrors('file');
        $this->assertDatabaseCount('suppliers',1);
        $this->assertDatabaseCount('product_supplier',1);
    }

    public function test_supplier_only_import_and_permissions_are_rechecked_at_confirmation(): void
    {
        $data = $this->sheets();
        unset($data['Supplier Barang']);
        $this->confirm($this->preview($data)->viewData('run')->token)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('suppliers',2);
        $this->assertDatabaseCount('product_supplier',1);
        $user = User::create(['name'=>'Staff','email'=>'staff-commit@test.com','password'=>'password','role'=>'kasir']);
        $this->actingAs($user);
        $this->post(route('master.suppliers.import.store'))->assertForbidden();
        DB::table('role_permissions')->insert(['role'=>'kasir','permission'=>'master.suppliers.import']);
        DB::table('role_permissions')->insert(['role'=>'kasir','permission'=>'master.suppliers.view']);
        DB::table('role_permissions')->insert(['role'=>'kasir','permission'=>'master.products.view']);
        $run = $this->preview()->viewData('run');
        DB::table('role_permissions')->where('role','kasir')->where('permission','master.products.view')->delete();
        $this->confirm($run->token)->assertSessionHasErrors('file');
        $this->assertSame('pending',$run->fresh()->status);
        DB::table('role_permissions')->where('role','kasir')->where('permission','master.suppliers.import')->delete();
        $this->confirm($run->token)->assertForbidden();
    }
}
