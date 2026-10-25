<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, ProductUnit, PurchaseOrder, Stock, Supplier, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyInputFormsTest extends TestCase
{
    use RefreshDatabase;

    public function test_fractional_purchase_prices_survive_supplier_and_order_round_trips(): void
    {
        $owner = User::create(['name'=>'Owner','email'=>'currency@test.com','password'=>'password','role'=>'owner']);
        $this->actingAs($owner);
        $category = Category::create(['name'=>'Minuman']);
        $unit = Unit::create(['name'=>'Botol']);
        $location = Location::create(['name'=>'Toko']);
        $product = Product::create(['name'=>'Produk Uji Uang','category_id'=>$category->id,'buy_unit_id'=>$unit->id,
            'buy_price'=>'2300.50','sell_price_wholesale'=>3500,'is_expirable'=>false,'is_active'=>true]);
        $productUnit = ProductUnit::create(['product_id'=>$product->id,'unit_id'=>$unit->id,'conversion_to_base'=>1,
            'buy_price'=>'2300.50','sell_price'=>3500,'is_base_unit'=>true,'is_default_purchase'=>true,'is_default_sale'=>true]);
        Stock::create(['product_id'=>$product->id,'location_id'=>$location->id,'quantity'=>10]);
        $supplier = Supplier::create(['name'=>'Supplier Uji Uang']);
        $this->put(route('master.suppliers.update', $supplier), ['name'=>$supplier->name,'products'=>[
            $product->id=>['selected'=>1,'buy_price'=>'2300.50'],
        ]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('product_supplier', ['product_id'=>$product->id,'supplier_id'=>$supplier->id,'buy_price'=>'2300.50']);
        $this->post(route('purchase.orders.store'), ['supplier_id'=>$supplier->id,'order_date'=>today()->toDateString(),
            'items'=>[['product_id'=>$product->id,'product_unit_id'=>$productUnit->id,'quantity_ordered'=>2,'buy_price'=>'2300.50']],
        ])->assertSessionHasNoErrors();
        $order = PurchaseOrder::firstOrFail();
        $this->assertDatabaseHas('purchase_order_items', ['purchase_order_id'=>$order->id,'buy_price'=>'2300.50']);

        foreach ([
            'product'=>route('master.products.edit', $product),
            'supplier'=>route('master.suppliers.edit', $supplier),
            'purchase'=>route('purchase.orders.edit', $order),
            'sale'=>route('sale.sales.create'),
            'expense'=>route('expenses.create'),
            'tax'=>route('taxes.create'),
        ] as $name=>$url) {
            $page = $this->get($url)->assertOk();
            if (getenv('CAPTURE_CURRENCY_UI') === '1') {
                $directory = storage_path('framework/testing/currency');
                if (!is_dir($directory)) mkdir($directory, 0777, true);
                file_put_contents($directory.'/'.$name.'.html', $page->getContent());
            }
        }
    }
}
