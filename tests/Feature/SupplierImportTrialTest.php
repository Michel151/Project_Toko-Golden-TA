<?php

namespace Tests\Feature;

use App\Exports\SupplierImportTrialTemplateExport;
use App\Models\{Category,Product,ProductUnit,Supplier,Unit,User};
use App\Services\SupplierImportTrialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SupplierImportTrialTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create(['name'=>'Owner','email'=>'supplier-import@example.com','password'=>'password','role'=>'owner']));
        $category=Category::create(['name'=>'Kategori']);
        $pcs=Unit::create(['name'=>'Pcs']); $box=Unit::create(['name'=>'Dus']);
        $this->product=Product::create(['name'=>'Barang Terdaftar','barcode'=>'001234','category_id'=>$category->id,'buy_unit_id'=>$box->id,'buy_price'=>24000,'sell_price_wholesale'=>30000,'is_active'=>true]);
        foreach([[$pcs,1,true,false],[$box,24,false,true]] as [$unit,$conversion,$base,$purchase]) ProductUnit::create(['product_id'=>$this->product->id,'unit_id'=>$unit->id,'conversion_to_base'=>$conversion,'buy_price'=>1000*$conversion,'sell_price'=>1250*$conversion,'is_base_unit'=>$base,'is_default_purchase'=>$purchase]);
        $this->supplier=Supplier::create(['name'=>'Supplier Lama','address'=>'Alamat Asli','phone'=>'081234']);
        $this->supplier->products()->attach($this->product->id,['buy_price'=>24000]);
    }

    private function data(): array
    {
        return ['Master Supplier'=>[SupplierImportTrialService::HEADINGS['Master Supplier'],['NEW','Supplier Baru','Alamat Baru','081000','']],
            'Supplier Barang'=>[SupplierImportTrialService::HEADINGS['Supplier Barang'],['001234','','NEW','Dus',48000],['',$this->product->id,'SUP-'.$this->supplier->id,'Pcs',1500]]];
    }

    private function upload(array $sheets): UploadedFile
    {
        \PhpOffice\PhpSpreadsheet\Cell\Cell::setValueBinder(new \PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder);
        $book=new Spreadsheet; $book->removeSheetByIndex(0);
        foreach($sheets as $title=>$rows){$sheet=$book->createSheet();$sheet->setTitle($title);$sheet->fromArray($rows);}
        // Barcode cells must stay text, including their leading zeros.
        $sheet=$book->getSheetByName('Supplier Barang');
        if($sheet) foreach($sheets['Supplier Barang'] as $index=>$row) if($index>0 && filled($row[0])) $sheet->setCellValueExplicit('A'.($index+1),(string)$row[0],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $file=tempnam(sys_get_temp_dir(),'supplier-trial-');
        try{(new Xlsx($book))->save($file);return UploadedFile::fake()->createWithContent('supplier.xlsx',file_get_contents($file));}
        finally{$book->disconnectWorksheets();unlink($file);}
    }

    public function test_supplier_import_matches_registered_products_without_writing_data(): void
    {
        $before=[];
        foreach(['suppliers','product_supplier','products','product_units','stocks','stock_batches','stock_movements'] as $table) $before[$table]=DB::table($table)->get()->toJson();
        $this->get(route('master.suppliers.index'))->assertOk()->assertSee('Import Supplier');
        $response=$this->post(route('master.suppliers.import.preview'),['file'=>$this->upload($this->data())]);
        $response->assertOk()->assertSee('Supplier Baru')->assertSee('Barang Terdaftar')->assertSee('Sudah terhubung (tidak diubah)');
        $preview=$response->viewData('preview');
        $this->assertSame(0,$preview['error_count'],json_encode($preview['errors']));
        $this->assertSame(2,$preview['supplier_link_count']);
        $this->assertSame(2000.0,$preview['supplier_links'][0]['base_price']);
        $this->assertSame('001234',$preview['supplier_links'][0]['barcode']);
        $this->assertSame(24000.0,$preview['supplier_links'][1]['existing_price']);
        foreach($before as $table=>$rows) $this->assertSame($rows,DB::table($table)->get()->toJson(),$table.' must not change');
    }

    public function test_downloaded_template_round_trips_and_supplier_only_import_is_supported(): void
    {
        $export=new SupplierImportTrialTemplateExport;
        $sheets=$export->sheets();
        $this->assertSame(['Master Supplier','Supplier Barang','Referensi Barang'],array_map(fn($sheet)=>$sheet->title(),$sheets));
        $bytes=Excel::raw($export,\Maatwebsite\Excel\Excel::XLSX);
        $response=$this->post(route('master.suppliers.import.preview'),['file'=>UploadedFile::fake()->createWithContent('supplier.xlsx',$bytes)]);
        $response->assertOk();
        $this->assertSame(0,$response->viewData('preview')['error_count']);
        $this->assertSame(2,$response->viewData('preview')['supplier_link_count']);
        $data=$this->data();unset($data['Supplier Barang']);
        $response=$this->post(route('master.suppliers.import.preview'),['file'=>$this->upload($data)]);
        $response->assertOk();
        $this->assertSame(1,$response->viewData('preview')['supplier_count']);
        $this->assertSame(0,$response->viewData('preview')['supplier_link_count']);
        $this->get(route('master.suppliers.import.template'))->assertOk();
    }

    public function test_bad_product_references_units_and_duplicate_links_are_reported(): void
    {
        foreach([['1234','','NEW','Dus',1],['',$this->product->id+100,'NEW','Dus',1],['bad',$this->product->id,'NEW','Dus',1],['001234','','NEW','Unknown',1],['','','NEW','Dus',1]] as $row){
            $data=$this->data();$data['Supplier Barang']=[SupplierImportTrialService::HEADINGS['Supplier Barang'],$row];
            $preview=app(SupplierImportTrialService::class)->preview($data,true);
            $this->assertGreaterThan(0,$preview['error_count']);$this->assertSame(0,$preview['supplier_link_count']);
        }
        $data=$this->data();$data['Supplier Barang'][]=['',$this->product->id,'NEW','Pcs',1];
        $this->assertStringContainsString('Hubungan barang-supplier ganda',json_encode(app(SupplierImportTrialService::class)->preview($data,true)['errors']));
        $this->product->update(['is_active'=>false]);
        $this->assertStringContainsString('tidak aktif',json_encode(app(SupplierImportTrialService::class)->preview($this->data(),true)['errors']));
    }

    public function test_reference_sheet_cannot_create_products_and_existing_supplier_is_not_overwritten(): void
    {
        $data=$this->data();
        $data['Master Supplier'][]=['SUP-'.$this->supplier->id,'Changed','Changed','099999',$this->supplier->id];
        $data['Referensi Barang']=[SupplierImportTrialService::HEADINGS['Referensi Barang'],[999999,'Fake','Fake','Dus']];
        $preview=app(SupplierImportTrialService::class)->preview($data,true);
        $this->assertSame(0,$preview['error_count']);$this->assertCount(1,$preview['warnings']);
        $this->assertSame('Alamat Asli',collect($preview['suppliers'])->firstWhere('id',$this->supplier->id)['address']);
        $this->assertDatabaseCount('products',1);$this->assertDatabaseCount('suppliers',1);
    }

    public function test_import_permission_and_product_reference_privacy_are_enforced(): void
    {
        $user=User::create(['name'=>'Staff','email'=>'supplier-staff@example.com','password'=>'password','role'=>'kasir']);
        DB::table('role_permissions')->insert(['role'=>'kasir','permission'=>'master.suppliers.view']);
        $this->actingAs($user);
        $this->get(route('master.suppliers.index'))->assertOk()->assertDontSee('href="'.route('master.suppliers.import').'"',false);
        $this->get(route('master.suppliers.import'))->assertForbidden();
        $this->get(route('master.suppliers.import.template'))->assertForbidden();
        $this->post(route('master.suppliers.import.preview'))->assertForbidden();
        DB::table('role_permissions')->insert(['role'=>'kasir','permission'=>'master.suppliers.import']);
        $this->get(route('master.suppliers.import'))->assertOk();
        $this->assertSame([SupplierImportTrialService::HEADINGS['Referensi Barang']],(new SupplierImportTrialTemplateExport)->sheets()[2]->array());
        $response=$this->post(route('master.suppliers.import.preview'),['file'=>$this->upload($this->data())]);
        $response->assertOk()->assertSee('hak akses melihat Master Barang')->assertDontSee('Barang Terdaftar');
    }
}
