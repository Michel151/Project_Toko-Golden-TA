<?php

namespace Tests\Feature;

use App\Models\{Category, Location, Product, Supplier, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_lists_use_fixed_pages_and_product_filters_survive_navigation(): void
    {
        $owner = User::create(['name'=>'Owner', 'email'=>'pagination@test.com', 'password'=>'password', 'role'=>'owner']);
        $this->actingAs($owner);
        for ($i=0; $i<26; $i++) {
            $category = Category::create(['name'=>'Kategori '.$i]);
            $unit = Unit::create(['name'=>'Satuan '.$i]);
            Location::create(['name'=>'Lokasi '.$i]);
            Supplier::create(['name'=>'Supplier '.$i]);
            $user = $owner->replicate();
            $user->email = 'user-'.$i.'@pagination.test';
            $user->save();
        }
        $attributes = ['category_id'=>$category->id, 'buy_unit_id'=>$unit->id,
            'buy_price'=>1000, 'sell_price_wholesale'=>2000, 'is_active'=>true];
        for ($i=0; $i<26; $i++) Product::create(['name'=>'Target '.str_pad($i, 2, '0', STR_PAD_LEFT)] + $attributes);
        Product::create(['name'=>'Target Inactive', 'is_active'=>false] + $attributes);
        Product::create(['name'=>'Target Other Category', 'category_id'=>Category::first()->id] + $attributes);
        Product::create(['name'=>'Unrelated'] + $attributes);
        foreach (['categories', 'units', 'locations', 'suppliers', 'users'] as $list) {
            $response = $this->get(route('master.'.$list.'.index', ['per_page'=>100]))->assertOk();
            $rows = $response->viewData($list);
            $this->assertSame(25, $rows->perPage());
            $this->assertCount(25, $rows);
            $second = $this->get($rows->url(2))->assertOk()->viewData($list);
            $this->assertEmpty(array_intersect($rows->pluck('id')->all(), $second->pluck('id')->all()));
        }
        $filters = ['search'=>'Target', 'category_id'=>$category->id, 'status'=>'active'];
        $first = $this->get(route('master.products.index', $filters))->assertOk()->viewData('products');
        $this->assertSame(25, $first->perPage());
        $this->assertSame(26, $first->total());
        parse_str(parse_url($first->url(2), PHP_URL_QUERY), $query);
        foreach ($filters as $key=>$value) $this->assertEquals($value, $query[$key]);
        $second = $this->get($first->url(2))->assertOk()->viewData('products');
        $this->assertCount(1, $second);
        $this->assertSame('Target 25', $second->first()->name);
        $this->assertSame(26, $second->total());
    }
}
