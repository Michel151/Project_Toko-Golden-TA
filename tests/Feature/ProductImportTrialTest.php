<?php

namespace Tests\Feature;

use App\Exports\ProductImportTrialTemplateExport;
use App\Models\User;
use App\Models\Supplier;
use App\Services\ProductImportTrialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProductImportTrialTest extends TestCase
{
    use RefreshDatabase;

    private function data(): array
    {
        return [
            'Barang'=>[ProductImportTrialService::HEADINGS['Barang'],['A','Barang A','00123','Kategori','Dus','Bks',0,'tidak']],
            'Satuan'=>[ProductImportTrialService::HEADINGS['Satuan'],['A','Dus',5,'Bal',500,1000],['A','Bal',10,'Slop',100,200],['A','Slop',10,'Bks',10,20],['A','Bks',1,'Bks',1,2]],
            'Stok Awal'=>[ProductImportTrialService::HEADINGS['Stok Awal'],['A','Toko',2,'Dus',''],['A','Toko',3,'Bks',''],['A','Gudang 1',20,'Dus',''],['A','Gudang 2',25,'Bal','']],
        ];
    }

    private function upload(array $sheets): UploadedFile
    {
        \PhpOffice\PhpSpreadsheet\Cell\Cell::setValueBinder(new \PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder);
        $book=new Spreadsheet;
        $book->removeSheetByIndex(0);
        foreach($sheets as $title=>$rows){
            $sheet=$book->createSheet();
            $sheet->setTitle($title);
            $sheet->fromArray($rows);
        }
        $file=tempnam(sys_get_temp_dir(),'trial-xlsx-');
        try {
            (new Xlsx($book))->save($file);
            return UploadedFile::fake()->createWithContent('trial.xlsx',file_get_contents($file));
        } finally {
            $book->disconnectWorksheets();
            unlink($file);
        }
    }

    public function test_trial_converts_chained_units_and_combines_mixed_stock_without_writes(): void
    {
        $user=User::create(['name'=>'Owner','email'=>'trial@example.com','password'=>'password','role'=>'owner']);
        $this->actingAs($user);
        $before=[];
        foreach(['products','product_units','stocks','stock_batches','stock_movements','categories','units','locations','suppliers'] as $table) $before[$table]=DB::table($table)->count();
        $this->get(route('master.products.import'))->assertOk()->assertSee('Import Barang')->assertDontSee('Import Lama');
        $response=$this->post(route('master.products.import.preview'),['file'=>$this->upload($this->data())]);
        $response->assertOk()->assertSee('1.003 Bks')->assertSee('10.000 Bks')->assertSee('2.500 Bks')->assertSee('Tidak ada data yang disimpan atau diubah.');
        $preview=$response->viewData('preview');
        $this->assertSame(0,$preview['error_count']);
        $this->assertSame(500.0,$preview['products'][0]['units'][0]['conversion']);
        $this->assertSame(1003.0,$preview['stocks'][0]['quantity']);
        foreach($before as $table=>$count) $this->assertSame($count,DB::table($table)->count(),$table.' must remain unchanged');
    }

    public function test_generated_template_round_trips_with_text_barcodes_and_dropdowns(): void
    {
        $user=User::create(['name'=>'Owner','email'=>'template-trial@example.com','password'=>'password','role'=>'owner']);
        $this->actingAs($user);
        $bytes=Excel::raw(new ProductImportTrialTemplateExport,\Maatwebsite\Excel\Excel::XLSX);
        $file=tempnam(sys_get_temp_dir(),'import-template-');
        try {
            file_put_contents($file,$bytes);
            $book=\PhpOffice\PhpSpreadsheet\IOFactory::load($file);
            $stockSheet=$book->getSheetByName('Stok Awal');
            $this->assertSame('E',$stockSheet->getHighestDataColumn());
            $this->assertSame([ProductImportTrialService::HEADINGS['Stok Awal']],$stockSheet->rangeToArray('A1:E1'));
            $this->assertSame(now('Asia/Jakarta')->addMonths(6)->toDateString(),$stockSheet->getCell('E6')->getValue());
            $this->assertSame('A2',$stockSheet->getFreezePane());
            $this->assertSame('"Dus,Bal,Slop,Bks,Pcs"',$stockSheet->getCell('D2')->getDataValidation()->getFormula1());
        } finally {
            if (isset($book)) $book->disconnectWorksheets();
            unlink($file);
        }
        $response=$this->post(route('master.products.import.preview'),['file'=>UploadedFile::fake()->createWithContent('template.xlsx',$bytes)]);
        $response->assertOk();
        $preview=$response->viewData('preview');
        $this->assertSame(0,$preview['error_count'],json_encode($preview['errors']));
        $this->assertSame('0012345678901',$preview['products'][0]['barcode']);
        $this->assertSame(51.0,$preview['stocks'][3]['quantity']);
        $this->assertSame(0,$preview['supplier_count']);
        $this->assertSame(0,$preview['supplier_link_count']);
        $this->get(route('master.products.import.template'))->assertOk();
        $this->assertDatabaseCount('products',0);
    }

    public function test_empty_trailing_columns_are_ignored_but_populated_extra_columns_are_rejected(): void
    {
        $data = $this->data();
        foreach ($data['Satuan'] as &$row) $row = array_pad($row, 14, null);
        unset($row);
        $service = app(ProductImportTrialService::class);
        $preview = $service->preview($data);
        $this->assertSame(0, $preview['error_count'], json_encode($preview['errors']));
        $this->assertSame(1003.0, $preview['stocks'][0]['quantity']);

        $data['Satuan'][1][6] = 'Data tanpa judul';
        $this->assertStringContainsString('Judul kolom harus sesuai template', json_encode($service->preview($data)['errors']));
        $data['Satuan'][1][6] = null;
        $data['Satuan'][0][6] = 'kolom_tambahan';
        $this->assertStringContainsString('Judul kolom harus sesuai template', json_encode($service->preview($data)['errors']));
        $data['Satuan'][0][6] = 'satuan';
        $this->assertStringContainsString('Judul kolom harus sesuai template', json_encode($service->preview($data)['errors']));
    }

    public function test_cycles_missing_units_and_duplicate_codes_are_reported_with_rows(): void
    {
        $data=$this->data();
        $data['Satuan'][2][3]='Dus';
        $preview=app(ProductImportTrialService::class)->preview($data);
        $this->assertGreaterThan(0,$preview['error_count']);
        $this->assertStringContainsString('berputar',json_encode($preview['errors']));
        $this->assertSame([],$preview['products']);
        $data=$this->data();
        $data['Satuan'][1][3]='Tidak Ada';
        $this->assertStringContainsString('tidak ditemukan',json_encode(app(ProductImportTrialService::class)->preview($data)['errors']));
        $data=$this->data();
        $data['Barang'][]=$data['Barang'][1];
        $this->assertStringContainsString('Kode barang ganda',json_encode(app(ProductImportTrialService::class)->preview($data)['errors']));
    }

    public function test_batches_keep_expiry_separate_and_exclude_expired_stock_from_sellable_totals(): void
    {
        $data=$this->data();
        $data['Barang'][1][7]='ya';
        $data['Stok Awal']=[['kode_barang','lokasi','jumlah','satuan','kode_batch','tanggal_kedaluwarsa'],
            ['A','Toko',2,'Dus','B1',now('Asia/Jakarta')->addDay()->toDateString()],
            ['A','Toko',3,'Bks','B1',now('Asia/Jakarta')->addDay()->toDateString()],
            ['A','Toko',1,'Dus','B2',now('Asia/Jakarta')->subDay()->toDateString()],
        ];
        $preview=app(ProductImportTrialService::class)->preview($data);
        $this->assertSame(0,$preview['error_count']);
        $this->assertSame(2,$preview['stock_count']);
        $this->assertSame(1003.0,$preview['stocks'][0]['sellable']);
        $this->assertSame(0,$preview['stocks'][1]['sellable']);
        $this->assertCount(1,$preview['warnings']);
        $data['Stok Awal'][2][5]='2020-01-01';
        $this->assertStringContainsString('tanggal kedaluwarsa berbeda',json_encode(app(ProductImportTrialService::class)->preview($data)['errors']));
        $data['Stok Awal'][1][5]='2026-02-31';
        $this->assertStringContainsString('yang valid',json_encode(app(ProductImportTrialService::class)->preview($data)['errors']));
    }

    public function test_legacy_optional_code_column_accepts_blank_codes_with_different_expiry_dates(): void
    {
        $data=$this->data();
        $data['Barang'][1][7]='ya';
        $data['Stok Awal']=[['kode_barang','lokasi','jumlah','satuan','kode_batch','tanggal_kedaluwarsa'],
            ['A','Toko',1,'Dus','',now('Asia/Jakarta')->addDay()->toDateString()],
            ['A','Toko',1,'Dus','',now('Asia/Jakarta')->addMonth()->toDateString()],
            ['A','Toko',1,'Bks','0',now('Asia/Jakarta')->addMonth()->toDateString()],
        ];
        $service=app(ProductImportTrialService::class);
        $preview=$service->preview($data);
        $this->assertSame(0,$preview['error_count'],json_encode($preview['errors']));
        $this->assertSame(3,$preview['stock_count']);
        $this->assertNull($preview['stocks'][0]['batch']);
        $this->assertSame('0',$preview['stocks'][2]['batch']);
        $data['Stok Awal'][0][]='tambahan';
        $this->assertStringContainsString('Judul kolom harus sesuai template',json_encode($service->preview($data)['errors']));
        $this->assertDatabaseCount('stock_batches',0);
    }

    public function test_trial_rejects_formulas_and_requires_import_permission(): void
    {
        $user=User::create(['name'=>'Owner','email'=>'formula-trial@example.com','password'=>'password','role'=>'owner']);
        $this->actingAs($user);
        $data=$this->data(); $data['Satuan'][1][2]='=2+3';
        $this->post(route('master.products.import.preview'),['file'=>$this->upload($data)])->assertSessionHasErrors('file');
        $staff=User::create(['name'=>'Kasir','email'=>'staff-trial@example.com','password'=>'password','role'=>'kasir']);
        $this->actingAs($staff);
        $this->get(route('master.products.import'))->assertForbidden();
        $this->get(route('master.products.import.template'))->assertForbidden();
        $this->post(route('master.products.import.preview'))->assertForbidden();
        $this->assertDatabaseCount('products',0);
    }

    public function test_supplier_profiles_and_links_are_previewed_without_changes_to_existing_data(): void
    {
        $user=User::create(['name'=>'Owner','email'=>'supplier-trial@example.com','password'=>'password','role'=>'owner']);
        $this->actingAs($user);
        $supplier=Supplier::create(['name'=>'Supplier Lama','address'=>'Alamat asli','phone'=>'081234']);
        $data=$this->data();
        $data['Master Supplier']=[ProductImportTrialService::HEADINGS['Master Supplier'],
            ['OLD','Nama diubah','Alamat diubah','089999',$supplier->id],['NEW','Supplier Baru','Alamat baru','081111','']];
        $data['Supplier Barang']=[ProductImportTrialService::HEADINGS['Supplier Barang'],['A','OLD','Dus',500],['A','NEW','Bal',150]];
        $response=$this->post(route('master.products.import.preview'),['file'=>$this->upload($data)]);
        $response->assertOk()->assertSee('Supplier Lama')->assertSee('Supplier Baru');
        $preview=$response->viewData('preview');
        $this->assertSame(0,$preview['error_count']);
        $this->assertSame(2,$preview['supplier_link_count']);
        $this->assertSame(1.0,$preview['supplier_links'][0]['base_price']);
        $this->assertSame(1.5,$preview['supplier_links'][1]['base_price']);
        $this->assertSame('Alamat asli',$preview['suppliers'][0]['address']);
        $this->assertCount(1,$preview['warnings']);
        $this->assertDatabaseCount('suppliers',1);
        $this->assertDatabaseCount('products',0);
        $this->assertDatabaseCount('product_supplier',0);
        $this->assertSame('Alamat asli',$supplier->fresh()->address);
        $this->assertSame('081234',$supplier->fresh()->phone);
    }

    public function test_registered_supplier_can_be_referenced_without_master_sheet_and_duplicates_are_blocked(): void
    {
        $supplier=Supplier::create(['name'=>'Lama']);
        $data=$this->data();
        $data['Supplier Barang']=[ProductImportTrialService::HEADINGS['Supplier Barang'],['A','SUP-'.$supplier->id,'Dus',500]];
        $preview=app(ProductImportTrialService::class)->preview($data,true);
        $this->assertSame(0,$preview['error_count']);
        $this->assertSame(1,$preview['supplier_link_count']);
        $this->assertSame('Terdaftar',$preview['suppliers'][0]['status']);
        $data['Master Supplier']=[ProductImportTrialService::HEADINGS['Master Supplier'],['ALIAS','Lama','','',$supplier->id]];
        $data['Supplier Barang'][]=['A','ALIAS','Bal',100];
        $preview=app(ProductImportTrialService::class)->preview($data,true);
        $this->assertSame(1,$preview['supplier_count']);
        $this->assertSame(1,$preview['supplier_link_count']);
        $this->assertStringContainsString('Hubungan barang-supplier ganda',json_encode($preview['errors']));
    }

    public function test_unknown_supplier_invalid_unit_negative_price_and_duplicate_supplier_are_reported(): void
    {
        $base=$this->data();
        $base['Master Supplier']=[ProductImportTrialService::HEADINGS['Master Supplier'],['NEW','Nama','','','']];
        foreach ([['A','MISSING','Dus',500],['A','NEW','Unknown',500],['A','NEW','Dus',-1],['UNKNOWN','NEW','Dus',500]] as $row) {
            $data=$base;
            $data['Supplier Barang']=[ProductImportTrialService::HEADINGS['Supplier Barang'],$row];
            $preview=app(ProductImportTrialService::class)->preview($data);
            $this->assertGreaterThan(0,$preview['error_count']);
            $this->assertSame(0,$preview['supplier_link_count']);
        }
        $base['Master Supplier'][]=['new','Lain','','',''];
        $preview=app(ProductImportTrialService::class)->preview($base);
        $this->assertStringContainsString('Kode supplier ganda',json_encode($preview['errors']));
        $base['Master Supplier'][2]=['SUP-999','Tidak Ada','','',''];
        $this->assertStringContainsString('ID supplier tidak ditemukan',json_encode(app(ProductImportTrialService::class)->preview($base,true)['errors']));
    }

    public function test_supplier_contact_data_is_not_exposed_without_supplier_view_permission(): void
    {
        $supplier=Supplier::create(['name'=>'Secret Supplier','address'=>'Secret Address','phone'=>'Secret Phone']);
        $user=User::create(['name'=>'Kasir','email'=>'private-supplier@example.com','password'=>'password','role'=>'kasir']);
        foreach(['master.products.view','master.products.import'] as $permission) DB::table('role_permissions')->insert(['role'=>'kasir','permission'=>$permission]);
        $this->actingAs($user);
        $sheets=(new ProductImportTrialTemplateExport)->sheets();
        $this->assertCount(3,$sheets);
        $this->assertStringNotContainsString('Secret Supplier',json_encode(array_map(fn($sheet)=>$sheet->array(),$sheets)));
        $data=$this->data();
        $data['Supplier Barang']=[ProductImportTrialService::HEADINGS['Supplier Barang'],['A','SUP-'.$supplier->id,'Dus',500]];
        $response=$this->post(route('master.products.import.preview'),['file'=>$this->upload($data)]);
        $response->assertOk()->assertSee('hak akses Master Supplier')->assertDontSee('Secret Supplier')->assertDontSee('Secret Address')->assertDontSee('Secret Phone');
    }
}
