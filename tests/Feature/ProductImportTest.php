<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Regression harness for the retired importer; this route exists only in tests.
        \Illuminate\Support\Facades\Route::post('/test/legacy-product-import',
            [\App\Http\Controllers\MasterData\ProductImportController::class, 'store'])
            ->middleware(['web', 'auth', 'role:owner'])
            ->name('legacy-product-import-test');
        \Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();
    }

    private function owner(): User
    {
        return User::create(['name' => 'Owner', 'email' => 'import@example.com', 'password' => 'password', 'role' => 'owner']);
    }

    private function upload(array $rows, ?array $headings = null): UploadedFile
    {
        $export = new class($rows, $headings ?? ProductImportService::HEADINGS) implements FromArray, WithStrictNullComparison {
            public function __construct(private array $rows, private array $headings) {}

            public function array(): array
            {
                return [$this->headings, ...$this->rows];
            }
        };
        return UploadedFile::fake()->createWithContent('barang.xlsx', Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX));
    }

    private function row(string $location = 'Gudang', mixed $stock = 77, ?string $supplier = null): array
    {
        return ['Air Mineral', '0012345678901', 'Minuman', 'Pcs', 3000, 4000, $location, $stock, 10, $supplier];
    }

    public function test_only_one_import_page_uses_preview_and_three_sheet_template(): void
    {
        $this->actingAs($this->owner())->get(route('master.products.import'))->assertOk()
            ->assertSee('Import Barang')->assertSee('Periksa & Pratinjau', false)
            ->assertDontSee('Import Lama')->assertDontSee('Import Multi Satuan')
            ->assertDontSee('href="'.route('master.suppliers.import').'"', false);
        $response = $this->get(route('master.products.import.template'))
            ->assertOk()->assertDownload('template-import-barang.xlsx');
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($response->baseResponse->getFile()->getPathname());
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

    public function test_excel_import_directly_creates_product_units_and_multiple_location_stocks(): void
    {
        $this->actingAs($this->owner())->post(route('legacy-product-import-test'), [
            'file' => $this->upload([$this->row(), $this->row('Toko', 12)]), 'import_stock' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect(route('master.products.index'));

        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('products', ['barcode' => '0012345678901', 'min_stock_total' => 10]);
        $this->assertDatabaseHas('product_units', ['conversion_to_base' => 1, 'is_base_unit' => 1, 'sell_price' => 4000]);
        $this->assertDatabaseHas('stocks', ['quantity' => 77]);
        $this->assertDatabaseHas('stocks', ['quantity' => 12]);
        $this->assertDatabaseCount('stock_movements', 2);
    }

    public function test_invalid_later_row_rolls_back_all_changes(): void
    {
        $this->actingAs($this->owner())->post(route('legacy-product-import-test'), [
            'file' => $this->upload([$this->row(), $this->row('Toko', -1)]), 'import_stock' => 1,
        ])->assertSessionHasErrors('file');

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('categories', 0);
        $this->assertDatabaseCount('stocks', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_existing_stock_is_replaced_only_when_selected_and_blank_stock_is_preserved(): void
    {
        $this->actingAs($this->owner());
        app(ProductImportService::class)->import([ProductImportService::HEADINGS, $this->row()], true);
        $product = Product::firstOrFail();
        $product->update(['buy_price' => 2500, 'is_expirable' => true]);
        $this->post(route('legacy-product-import-test'), ['file' => $this->upload([$this->row('Gudang', 0)])])
            ->assertSessionHasNoErrors();
        $this->assertSame(77.0, $product->totalStock());
        $this->post(route('legacy-product-import-test'), [
            'file' => $this->upload([$this->row('Gudang', null)]), 'import_stock' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(77.0, $product->totalStock());
        $this->post(route('legacy-product-import-test'), [
            'file' => $this->upload([$this->row('Gudang', 5)]), 'import_stock' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(5.0, $product->totalStock());
        $this->assertSame('2500.00', $product->fresh()->buy_price);
        $this->assertDatabaseHas('stock_movements', ['balance_before' => 77, 'balance_after' => 5, 'quantity' => -72]);
        $this->assertDatabaseHas('stock_batches', ['quantity_remaining' => 5]);
        $this->post(route('legacy-product-import-test'), [
            'file' => $this->upload([$this->row('Gudang', 0)]), 'import_stock' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(0.0, $product->totalStock());
        $this->assertDatabaseCount('products', 1);
    }

    public function test_duplicate_location_and_wrong_base_unit_are_rejected(): void
    {
        $this->actingAs($this->owner())->post(route('legacy-product-import-test'), [
            'file' => $this->upload([$this->row(), $this->row()]), 'import_stock' => 1,
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('products', 0);
        app(ProductImportService::class)->import([ProductImportService::HEADINGS, $this->row()], true);
        $row = $this->row();
        $row[3] = 'Dus';
        $this->post(route('legacy-product-import-test'), ['file' => $this->upload([$row]), 'import_stock' => 1])
            ->assertSessionHasErrors('file');
        $this->assertSame(77.0, Product::firstOrFail()->totalStock());
    }

    public function test_cashier_cannot_use_import_routes(): void
    {
        $user = $this->owner();
        $user->update(['role' => 'kasir']);
        $this->actingAs($user)->get(route('master.products.import'))->assertForbidden();
        $this->get(route('master.products.import.template'))->assertForbidden();
        $this->post(route('legacy-product-import-test'))->assertForbidden();
    }

    public function test_import_links_existing_and_new_suppliers_and_exposes_product_for_purchase(): void
    {
        $supplier = Supplier::create(['name' => 'PT Sumber Makmur', 'phone' => '0812345678']);
        $this->actingAs($this->owner())->post(route('legacy-product-import-test'), [
            'file' => $this->upload([$this->row(supplier: ' pt sumber makmur ; CV Jaya Abadi; PT Sumber Makmur ')]),
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('suppliers', 2);
        $this->assertDatabaseCount('product_supplier', 2);
        $this->assertDatabaseHas('product_supplier', ['supplier_id' => $supplier->id, 'buy_price' => 3000]);
        $this->assertSame('0812345678', $supplier->fresh()->phone);
        $this->get(route('purchase.orders.supplier-products', $supplier))
            ->assertOk()->assertJsonFragment(['id' => Product::firstOrFail()->id, 'name' => 'Air Mineral']);
    }

    public function test_reimport_preserves_supplier_prices_and_other_supplier_links(): void
    {
        $this->actingAs($this->owner());
        app(ProductImportService::class)->import([ProductImportService::HEADINGS, $this->row(supplier: 'Supplier A; Supplier B')], false);
        $product = Product::firstOrFail();
        $supplier = Supplier::where('name', 'Supplier A')->firstOrFail();
        $product->suppliers()->updateExistingPivot($supplier->id, ['buy_price' => 2500]);
        $this->post(route('legacy-product-import-test'), [
            'file' => $this->upload([$this->row(supplier: 'Supplier A; Supplier C')]),
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('product_supplier', 3);
        $this->assertDatabaseHas('product_supplier', ['supplier_id' => $supplier->id, 'buy_price' => 2500]);
        $this->post(route('legacy-product-import-test'), ['file' => $this->upload([$this->row()])])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('product_supplier', 3);
    }

    public function test_old_nine_column_file_still_imports_without_supplier(): void
    {
        $this->actingAs($this->owner())->post(route('legacy-product-import-test'), [
            'file' => $this->upload([array_slice($this->row(), 0, 9)], ProductImportService::REQUIRED_HEADINGS),
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('suppliers', 0);
    }

    public function test_invalid_later_row_rolls_back_supplier_creation_and_links(): void
    {
        $this->actingAs($this->owner())->post(route('legacy-product-import-test'), [
            'file' => $this->upload([$this->row(supplier: 'Supplier Baru'), $this->row('Toko', -1)]),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('suppliers', 0);
        $this->assertDatabaseCount('product_supplier', 0);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_ambiguous_supplier_name_cancels_import(): void
    {
        Supplier::create(['name' => 'Supplier Sama']);
        Supplier::create(['name' => 'Supplier Sama']);
        $this->actingAs($this->owner())->post(route('legacy-product-import-test'), [
            'file' => $this->upload([$this->row(supplier: 'Supplier Sama')]),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_supplier', 0);
    }
}
