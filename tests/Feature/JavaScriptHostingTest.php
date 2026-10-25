<?php

namespace Tests\Feature;

use App\Models\{Category, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JavaScriptHostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_menus_render_without_separate_application_script_requests(): void
    {
        $owner = User::create(['name'=>'Owner','email'=>'hosting@test.com','password'=>'password','role'=>'owner']);
        $this->actingAs($owner);
        Category::create(['name'=>'Kategori Uji Hosting']);
        foreach ([
            'categories'=>'master.categories.index',
            'products'=>'master.products.create',
            'suppliers'=>'master.suppliers.create',
            'purchase'=>'purchase.orders.create',
            'receiving'=>'purchase.receipts.create',
            'sale'=>'sale.sales.create',
            'transfers'=>'inventory.transfers.create',
            'opname'=>'inventory.opnames.create',
            'permissions'=>'permissions.index',
            'expense'=>'expenses.create',
            'tax'=>'taxes.create',
        ] as $name=>$route) {
            $this->checkPage($name, $this->get(route($route))->assertOk()->getContent());
        }
        foreach (['owner', 'kasir', 'staf_gudang'] as $role) {
            $user = $role === 'owner' ? $owner : User::create([
                'name'=>$role,'email'=>$role.'-hosting@test.com','password'=>'password','role'=>$role,
            ]);
            $this->checkPage('dashboard-'.$role, $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent());
        }
    }

    private function checkPage(string $name, string $html): void
    {
        preg_match_all('/<script\b[^>]*\bsrc=["\x27]([^"\x27]+)["\x27]/i', $html, $matches);
        foreach ($matches[1] as $url) {
            $this->assertSame('cdn.jsdelivr.net', parse_url($url, PHP_URL_HOST), 'Unexpected application script dependency: '.$url);
        }
        if (getenv('CAPTURE_HOSTING_UI') === '1') {
            $directory = storage_path('framework/testing/hosting');
            if (!is_dir($directory)) mkdir($directory, 0777, true);
            file_put_contents($directory.'/'.$name.'.html', $html);
        }
    }
}
