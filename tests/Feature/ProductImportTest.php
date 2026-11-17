<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::create(['name' => 'Owner', 'email' => 'import@example.com', 'password' => 'password', 'role' => 'owner']);
    }

    private function upload(array $rows): UploadedFile
    {
        $export = new class($rows, ['nama_barang', 'barcode', 'kategori', 'satuan_dasar', 'harga_beli', 'harga_jual', 'lokasi', 'stok_aktual', 'stok_minimum', 'supplier']) implements FromArray, WithStrictNullComparison
        {
            public function __construct(private array $rows, private array $headings) {}

            public function array(): array
            {
                return [$this->headings, ...$this->rows];
            }
        };

        return UploadedFile::fake()->createWithContent('barang.xlsx', Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX));
    }

    private function row(): array
    {
        return ['Air Mineral', '0012345678901', 'Minuman', 'Pcs', 3000, 4000, 'Gudang', 77, 10, null];
    }

    public function test_only_one_import_page_uses_preview_and_three_sheet_template(): void
    {
        $this->actingAs($this->owner())->get(route('master.products.import'))->assertOk()
            ->assertSee('Import Barang')->assertSee('Periksa & Pratinjau', false)
            ->assertDontSee('Import Lama')->assertDontSee('Import Multi Satuan')
            ->assertDontSee('href="'.route('master.suppliers.import').'"', false);
        $response = $this->get(route('master.products.import.template'))
            ->assertOk()->assertDownload('template-import-barang.xlsx');
        $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $this->assertSame(['Barang', 'Satuan', 'Stok Awal'], $book->getSheetNames());
        $this->get(route('master.products.import-trial', ['preview' => 'saved-token']))
            ->assertRedirect(route('master.products.import', ['preview' => 'saved-token']));
        $this->get(route('master.products.import-trial.template'))
            ->assertRedirect(route('master.products.import.template'));
    }

    public function test_legacy_upload_url_cannot_bypass_preview_or_confirmation(): void
    {
        $this->actingAs($this->owner())->post(route('master.products.import.preview'), [
            'file' => $this->upload([$this->row()]), 'import_stock' => 1,
        ])->assertSessionHasErrors('file');
        $this->post(route('master.products.import.store'), [
            'file' => $this->upload([$this->row()]), 'import_stock' => 1,
        ])->assertSessionHasErrors(['token', 'confirmed']);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('stocks', 0);
    }

    public function test_cashier_cannot_use_import_routes(): void
    {
        $user = $this->owner();
        $user->update(['role' => 'kasir']);
        $this->actingAs($user)->get(route('master.products.import'))->assertForbidden();
        $this->get(route('master.products.import.template'))->assertForbidden();
        $this->post(route('master.products.import.preview'))->assertForbidden();
        $this->post(route('master.products.import.store'))->assertForbidden();
    }
}
