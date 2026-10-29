<?php

namespace Tests\Feature;

use App\Models\{Category, Expense, Product, ProductUnit, Sale, Unit, User};
use App\Support\ReturnNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReturnNavigationTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-29 10:00:00','Asia/Jakarta'));
        $this->actingAs(User::create(['name'=>'Owner Navigasi','email'=>'navigation@example.com','password'=>'password','role'=>'owner']));
        $category=Category::create(['name'=>'Kategori']);
        $unit=Unit::create(['name'=>'Pcs']);
        $this->product=Product::create(['name'=>'Air Mineral','category_id'=>$category->id,'buy_unit_id'=>$unit->id,
            'buy_price'=>500,'sell_price_wholesale'=>1000,'is_active'=>true]);
        ProductUnit::create(['product_id'=>$this->product->id,'unit_id'=>$unit->id,'conversion_to_base'=>1,
            'buy_price'=>500,'sell_price'=>1000,'is_base_unit'=>true,'is_default_sale'=>true]);
        $this->sale=Sale::create(['sale_number'=>'NAV/001','sale_date'=>'2026-10-29','total_amount'=>1000,
            'payment_method'=>'tunai','amount_paid'=>1000,'change_amount'=>0,'status'=>'paid','created_by'=>auth()->id()]);
    }

    private function capture(string $name, $response): void
    {
        if (getenv('CAPTURE_RETURN_UI')!=='1') return;
        $dir=storage_path('framework/testing/return-navigation');
        if (!is_dir($dir)) mkdir($dir,0777,true);
        file_put_contents($dir.'/'.$name.'.html',$response->getContent());
    }

    public function test_detail_returns_to_report_with_dates_payment_filter_page_and_fragment(): void
    {
        $origin='/report/sale?from=2026-09-01&to=2026-10-29&page=3&per_page=50#transactions';
        $response=$this->get(route('sale.sales.show',[$this->sale,'return_to'=>$origin]))->assertOk();
        $response->assertSee('href="'.e($origin).'" data-back-link',false)->assertSee('btn-outline-secondary btn-sm',false);
        $this->capture('sale-detail',$response);
        $this->assertDatabaseCount('sales',1);
        $this->assertDatabaseCount('stock_movements',0);
    }

    public function test_cancel_returns_to_filtered_master_list_and_nested_edit_returns_to_detail(): void
    {
        $origin='/master/products?search=Air%20Mineral&category_id='.$this->product->category_id.'&status=active&page=3';
        $detail='/master/products/'.$this->product->id.'?'.http_build_query(['return_to'=>$origin]).'#units';
        $response=$this->get(route('master.products.edit',[$this->product,'return_to'=>$detail]))->assertOk();
        $response->assertSee('href="'.e($detail).'" data-back-link',false)->assertSee('Batal');
        $this->capture('product-edit',$response);
        $response=$this->get(route('master.products.show',[$this->product,'return_to'=>$origin]))->assertOk();
        $response->assertSee('href="'.e($origin).'" data-back-link',false);
        $this->capture('product-detail',$response);
        $this->capture('product-list',$this->get(route('master.products.index'))->assertOk());
    }

    public function test_direct_open_and_invalid_return_targets_use_the_safe_fallback(): void
    {
        foreach ([null,'https://evil.example/','//evil.example/','/\\evil.example/','/%5cevil.example/',
            '/dashboard%0d%0aLocation:evil','/sale/stock-snapshot','/sale/drafts','/logout','/not-a-page', ['bad']] as $target) {
            $response=$this->get(route('sale.sales.show',[$this->sale]+($target===null?[]:['return_to'=>$target])))->assertOk();
            $response->assertSee('href="'.route('sale.sales.index').'" data-back-link',false);
        }
    }

    public function test_same_origin_absolute_targets_are_normalized_and_reports_are_not_child_pages(): void
    {
        $this->get(route('sale.sales.show',[$this->sale,'return_to'=>url('/report/sale').'?page=2']))->assertOk()
            ->assertSee('href="/report/sale?page=2" data-back-link',false);
        $this->assertTrue(ReturnNavigation::isPage('report.profit-loss'));
        $this->assertFalse(ReturnNavigation::isChild('report.sale'));
        $this->assertFalse(ReturnNavigation::isPage('report.sale.pdf'));
        $this->assertFalse(ReturnNavigation::isChild('sale.product.data'));
    }

    public function test_return_validation_does_not_replace_the_current_bound_product_model(): void
    {
        $this->get(route('master.products.show',$this->product))->assertOk();
        $bound=request()->route()->parameter('product');
        $this->assertInstanceOf(Product::class,$bound);
        ReturnNavigation::path('/master/products/999');
        $this->assertSame($bound,request()->route()->parameter('product'));
    }

    public function test_save_to_list_preserves_origin_pagination_and_validation_retains_cancel_target(): void
    {
        $origin='/master/categories?page=3';
        $this->post(route('master.categories.store',['return_to'=>$origin]),['name'=>'Kategori Baru'])->assertRedirect($origin);
        $create=route('master.categories.create',['return_to'=>$origin]);
        $this->from($create)->post(route('master.categories.store',['return_to'=>$origin]),['name'=>''])->assertSessionHasErrors('name')->assertRedirect($create);
        $this->get($create)->assertOk()->assertSee('href="'.e($origin).'" data-back-link',false);
    }

    public function test_redirect_to_saved_expense_detail_preserves_the_original_report_context(): void
    {
        $origin='/report/profit-loss?period=quarterly&year=2026&quarter=3';
        $response=$this->post(route('expenses.store',['return_to'=>$origin]),[
            'submission_token'=>(string) Str::uuid(),'recognized_date'=>'2026-10-29','category'=>'salary',
            'description'=>'Beban Uji','amount'=>1000,'payment_status'=>'unpaid',
        ])->assertSessionHasNoErrors();
        $expense=Expense::firstOrFail();
        $response->assertRedirect('/expenses/'.$expense->id.'?'.http_build_query(['return_to'=>$origin]));
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('href="'.e($origin).'" data-back-link',false);
        $this->assertDatabaseCount('expenses',1);
        $this->assertDatabaseCount('stock_movements',0);
    }

    public function test_saving_an_edit_opened_from_detail_returns_to_that_detail_with_its_list_context(): void
    {
        $detail='/master/products/'.$this->product->id.'?'.http_build_query(['return_to'=>'/master/products?search=Air&page=3']);
        $this->put(route('master.products.update',[$this->product,'return_to'=>$detail]),[
            'name'=>'Air Mineral','category_id'=>$this->product->category_id,'is_active'=>1,
            'base_unit_index'=>0,'default_purchase_unit_index'=>0,'default_sale_unit_index'=>0,
            'product_units'=>[['unit_id'=>$this->product->buy_unit_id,'contains_quantity'=>1,'contains_unit_index'=>'','buy_price'=>500,'sell_price'=>1000]],
        ])->assertSessionHasNoErrors()->assertRedirect($detail);
        $this->assertDatabaseCount('products',1);
        $this->assertDatabaseCount('stock_movements',0);
    }

    public function test_password_cancel_is_available_only_when_password_change_is_optional(): void
    {
        $this->get(route('account.password.edit',['return_to'=>'/dashboard']))->assertOk()->assertSee('href="/dashboard" data-back-link',false);
        auth()->user()->forceFill(['must_change_password'=>true])->save();
        $this->get(route('account.password.edit',['return_to'=>'/dashboard']))->assertOk()->assertDontSee('href="/dashboard" data-back-link',false);
    }

    public function test_external_return_does_not_change_a_successful_save_redirect(): void
    {
        $this->post(route('master.categories.store',['return_to'=>'https://evil.example']),['name'=>'Kategori Aman'])
            ->assertRedirect(route('master.categories.index'));
    }
}
