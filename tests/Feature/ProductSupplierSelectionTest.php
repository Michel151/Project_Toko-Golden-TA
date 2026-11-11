<?php

namespace Tests\Feature;

use App\Models\{Category, Product, Supplier, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSupplierSelectionTest extends TestCase
{
    use RefreshDatabase;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create([
            'name' => 'Owner', 'email' => 'product-suppliers@test.com',
            'password' => 'password', 'role' => 'owner',
        ]));
        $unit = Unit::create(['name' => 'Dus']);
        $this->payload = [
            'name' => 'Barang Multi Supplier',
            'category_id' => Category::create(['name' => 'Minuman'])->id,
            'is_active' => 1,
            'product_units' => [[
                'unit_id' => $unit->id, 'contains_quantity' => 1,
                'contains_unit_index' => '', 'buy_price' => 12000, 'sell_price' => 15000,
            ]],
            'base_unit_index' => 0, 'default_purchase_unit_index' => 0, 'default_sale_unit_index' => 0,
        ];
    }

    public function test_multiple_suppliers_are_linked_and_available_in_purchase_orders(): void
    {
        $suppliers = collect(['Supplier A', 'Supplier B'])->map(fn ($name) => Supplier::create(['name' => $name]));
        $this->post(route('master.products.store'), $this->payload + ['supplier_ids' => $suppliers->pluck('id')->all()])
            ->assertSessionHasNoErrors()->assertRedirect(route('master.products.index'));
        $product = Product::firstOrFail();
        $this->assertCount(2, $product->suppliers);
        foreach ($suppliers as $supplier) {
            $this->assertTrue($supplier->products()->whereKey($product->id)->exists());
            $this->get(route('purchase.orders.supplier-products', $supplier))->assertOk()
                ->assertJsonFragment(['id' => $product->id, 'name' => $product->name, 'price' => 12000]);
        }
        $edit = $this->get(route('master.products.edit', $product))->assertOk();
        $document = new \DOMDocument();
        @$document->loadHTML($edit->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(2, $xpath->query('//select[@id="productSuppliers"]/option[@selected]')->length);
    }

    public function test_update_preserves_existing_supplier_prices_and_only_changes_this_products_links(): void
    {
        $suppliers = collect(['Supplier A', 'Supplier B', 'Supplier C'])->map(fn ($name) => Supplier::create(['name' => $name]));
        $this->post(route('master.products.store'), $this->payload)->assertSessionHasNoErrors();
        $product = Product::firstOrFail();
        $product->suppliers()->attach([
            $suppliers[0]->id => ['buy_price' => 10000],
            $suppliers[1]->id => ['buy_price' => 11000],
        ]);
        $otherProduct = $product->replicate();
        $otherProduct->barcode = 'OTHER-PRODUCT';
        $otherProduct->save();
        $otherProduct->suppliers()->attach($suppliers[1]->id, ['buy_price' => 9000]);

        $this->put(route('master.products.update', $product), $this->payload + [
            'supplier_ids' => [$suppliers[0]->id, $suppliers[2]->id],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('product_supplier', ['product_id' => $product->id, 'supplier_id' => $suppliers[0]->id, 'buy_price' => 10000]);
        $this->assertDatabaseMissing('product_supplier', ['product_id' => $product->id, 'supplier_id' => $suppliers[1]->id]);
        $this->assertDatabaseHas('product_supplier', ['product_id' => $product->id, 'supplier_id' => $suppliers[2]->id, 'buy_price' => 12000]);
        $this->assertDatabaseHas('product_supplier', ['product_id' => $otherProduct->id, 'supplier_id' => $suppliers[1]->id, 'buy_price' => 9000]);
    }

    public function test_omitted_selection_preserves_links_and_explicit_empty_selection_removes_them(): void
    {
        $supplier = Supplier::create(['name' => 'Supplier A']);
        $this->post(route('master.products.store'), $this->payload + ['supplier_ids' => [$supplier->id]])->assertSessionHasNoErrors();
        $product = Product::firstOrFail();
        $this->put(route('master.products.update', $product), $this->payload)->assertSessionHasNoErrors();
        $this->assertSame(1, $product->suppliers()->count());
        $this->put(route('master.products.update', $product), $this->payload + ['supplier_ids' => ''])->assertSessionHasNoErrors();
        $this->assertSame(0, $product->suppliers()->count());
    }

    public function test_invalid_or_duplicate_supplier_selection_does_not_create_a_product(): void
    {
        $supplier = Supplier::create(['name' => 'Supplier A']);
        foreach ([[$supplier->id, $supplier->id], [999999]] as $ids) {
            $this->post(route('master.products.store'), $this->payload + ['supplier_ids' => $ids])
                ->assertSessionHasErrors('supplier_ids.0');
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_supplier', 0);
    }

    public function test_validation_error_keeps_selected_suppliers_including_an_empty_selection(): void
    {
        $supplier = Supplier::create(['name' => 'Supplier A']);
        $this->post(route('master.products.store'), $this->payload + ['supplier_ids' => [$supplier->id]])->assertSessionHasNoErrors();
        $product = Product::firstOrFail();
        foreach ([[$supplier->id], ''] as $selection) {
            $invalidPayload = array_replace($this->payload, ['name' => '', 'supplier_ids' => $selection]);
            $this->from(route('master.products.edit', $product))->put(route('master.products.update', $product), $invalidPayload)
                ->assertSessionHasErrors('name');
            $page = $this->get(route('master.products.edit', $product))->assertOk();
            $document = new \DOMDocument();
            @$document->loadHTML($page->getContent());
            $xpath = new \DOMXPath($document);
            $this->assertSame(is_array($selection) ? 1 : 0, $xpath->query('//select[@id="productSuppliers"]/option[@selected]')->length);
        }
        $this->assertSame(1, $product->suppliers()->count());
    }
}
