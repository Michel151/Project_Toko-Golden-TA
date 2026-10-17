<?php

namespace Tests\Feature;

use App\Models\{Category, Product, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function sidebar(string $role): \DOMXPath
    {
        $user=User::create(['name'=>$role,'email'=>$role.'@sidebar.test','password'=>'password','role'=>$role]);
        $response=$this->actingAs($user)->get(route('dashboard'))->assertOk();
        $dom=new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        return new \DOMXPath($dom);
    }

    public function test_cashier_has_direct_links_without_submenus(): void
    {
        $xpath=$this->sidebar('kasir');
        $this->assertCount(0,$xpath->query('//*[@id="sidebar"]//button[contains(@class,"nav-section-toggle")]'));
        $this->assertCount(0,$xpath->query('//*[@id="sidebar"]//ul[contains(@class,"nav-submenu")]'));
        foreach(['sale.sales.index','inventory.stock.index'] as $route) {
            $this->assertCount(1,$xpath->query('//*[@id="sidebar"]//a[@href="'.route($route).'"]'));
        }
        $this->assertCount(0,$xpath->query('//*[@id="sidebar"]//a[@href="'.route('purchase.receipts.index').'"]'));
    }

    public function test_warehouse_links_are_direct_and_revoked_permissions_still_hide_links(): void
    {
        $xpath=$this->sidebar('staf_gudang');
        $this->assertCount(0,$xpath->query('//*[@id="sidebar"]//button[contains(@class,"nav-section-toggle")]'));
        foreach(['purchase.receipts.index','inventory.stock.index','inventory.transfers.index','inventory.opnames.index'] as $route) {
            $this->assertCount(1,$xpath->query('//*[@id="sidebar"]//a[@href="'.route($route).'"]'));
        }
        DB::table('role_permissions')->where('role','staf_gudang')->where('permission','purchase.receipts.view')->delete();
        $this->get(route('inventory.stock.index'))->assertOk()->assertDontSee('href="'.route('purchase.receipts.index').'"',false);
        $this->get(route('purchase.receipts.index'))->assertForbidden();
    }

    public function test_owner_keeps_grouped_navigation(): void
    {
        $xpath=$this->sidebar('owner');
        $this->assertGreaterThan(0,$xpath->query('//*[@id="sidebar"]//button[contains(@class,"nav-section-toggle")]')->length);
        $this->assertGreaterThan(0,$xpath->query('//*[@id="sidebar"]//ul[contains(@class,"nav-submenu")]')->length);
    }

    public function test_stock_card_keeps_monitoring_menu_active(): void
    {
        $this->sidebar('kasir');
        $category=Category::create(['name'=>'Kategori']);
        $unit=Unit::create(['name'=>'Pcs']);
        $product=Product::create(['name'=>'Barang','category_id'=>$category->id,'buy_unit_id'=>$unit->id,'buy_price'=>1000,'sell_price_wholesale'=>1500]);
        $response=$this->get(route('inventory.stock-card.show',$product))->assertOk();
        $dom=new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath=new \DOMXPath($dom);
        $this->assertCount(1,$xpath->query('//*[@id="sidebar"]//a[@href="'.route('inventory.stock.index').'" and @aria-current="page" and contains(@class,"active")]'));
    }
}
