<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\StockOpname;
use App\Models\Supplier;
use App\Models\User;
use App\Support\AccessPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => 'Pengguna '.$role, 'email' => Str::uuid().'@example.com', 'password' => 'password', 'role' => $role]);
    }

    private function setPermissions(string $role, array $permissions): void
    {
        DB::table('role_permissions')->where('role', $role)->delete();
        foreach ($permissions as $permission) {
            DB::table('role_permissions')->insert(compact('role', 'permission'));
        }
    }

    public function test_owner_can_manage_permissions_and_keeps_full_access(): void
    {
        $owner = $this->user('owner');
        $this->actingAs($owner)->get(route('permissions.index'))->assertOk()->assertSee('Simpan Hak Akses');
        $this->put(route('permissions.update', 'kasir'), ['permissions' => ['master.categories.view']])
            ->assertRedirect(route('permissions.index', ['role' => 'kasir']));
        $this->assertDatabaseHas('role_permissions', ['role' => 'kasir', 'permission' => 'master.categories.view']);
        $this->assertDatabaseMissing('role_permissions', ['role' => 'kasir', 'permission' => 'sale.sales.create']);
        $this->assertDatabaseHas('audit_logs', ['module' => 'Hak Akses', 'user_id' => $owner->id]);
        $this->get(route('master.products.create'))->assertOk();
        $this->put(route('permissions.update', 'owner'), ['permissions' => []])->assertNotFound();
    }

    public function test_staff_and_cashier_cannot_change_permissions_or_manage_users(): void
    {
        foreach (array_keys(AccessPermissions::ROLES) as $role) {
            $this->actingAs($this->user($role))->get(route('permissions.index'))->assertForbidden();
            $this->put(route('permissions.update', 'kasir'), ['permissions' => []])->assertForbidden();
            $this->get(route('master.users.index'))->assertForbidden();
            $this->get(route('database-backups.index'))->assertForbidden();
            $this->get(route('audit-logs.index'))->assertForbidden();
        }
    }

    public function test_owner_validates_known_permissions_and_view_dependency(): void
    {
        $this->actingAs($this->user('owner'));
        $this->put(route('permissions.update', 'kasir'), ['permissions' => ['users.manage']])->assertSessionHasErrors('permissions.0');
        $this->put(route('permissions.update', 'kasir'), ['permissions' => ['master.categories.create']])->assertSessionHasErrors('permissions');
        $this->assertDatabaseHas('role_permissions', ['role' => 'kasir', 'permission' => 'sale.sales.view']);
    }

    public function test_view_only_access_hides_actions_and_blocks_direct_mutations(): void
    {
        $this->setPermissions('kasir', ['master.categories.view']);
        $this->actingAs($this->user('kasir'))->get(route('master.categories.index'))->assertOk()
            ->assertDontSee(route('master.categories.create'), false);
        $this->get(route('master.categories.create'))->assertForbidden();
        $this->post(route('master.categories.store'), ['name' => 'Tidak boleh'])->assertForbidden();
        $this->assertDatabaseMissing('categories', ['name' => 'Tidak boleh']);
        $this->put(route('master.categories.update', 999), ['name' => 'Tidak boleh'])->assertForbidden();
        $this->delete(route('master.categories.destroy', 999))->assertForbidden();
    }

    public function test_granted_create_permission_allows_creation(): void
    {
        $this->setPermissions('staf_gudang', ['master.categories.view', 'master.categories.create']);
        $this->actingAs($this->user('staf_gudang'))->get(route('master.categories.create'))->assertOk();
        $this->post(route('master.categories.store'), ['name' => 'Kategori Baru'])->assertRedirect(route('master.categories.index'));
        $this->assertDatabaseHas('categories', ['name' => 'Kategori Baru']);
    }

    public function test_permissions_are_reloaded_on_the_next_request(): void
    {
        $this->actingAs($this->user('kasir'))->get(route('inventory.stock.index'))->assertOk();
        $this->setPermissions('kasir', []);
        $this->get(route('inventory.stock.index'))->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Transaksi Terakhir Saya');
    }

    public function test_empty_selection_revokes_all_access(): void
    {
        $this->actingAs($this->user('owner'))->put(route('permissions.update', 'kasir'), [])->assertRedirect();
        $this->assertSame(0, DB::table('role_permissions')->where('role', 'kasir')->count());
    }

    public function test_exports_helpers_and_special_actions_require_permissions(): void
    {
        $this->setPermissions('kasir', ['sale.sales.view', 'master.products.view']);
        $this->actingAs($this->user('kasir'));
        foreach (['master.products.import', 'master.products.import.template', 'sale.product.data', 'report.stock.pdf', 'report.stock.excel', 'purchase.receipts.order-items'] as $name) {
            $this->get(route($name, 999))->assertForbidden();
        }
        $this->post(route('master.products.import.store'))->assertForbidden();
        $this->post(route('sale.sales.complete', 999))->assertForbidden();
        $this->post(route('sale.sales.cancel', 999))->assertForbidden();
        $this->post(route('inventory.opnames.complete', 999))->assertForbidden();
    }

    public function test_unmapped_action_is_denied_even_for_owner(): void
    {
        Route::get('/permission-test-unmapped', fn () => 'secret')
            ->middleware(['web', 'auth', 'permission:master.products'])->name('master.products.unmapped');
        $this->actingAs($this->user('owner'))->get('/permission-test-unmapped')->assertForbidden();
    }

    public function test_all_business_routes_have_explicit_authorization(): void
    {
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName() ?? '';
            if (! preg_match('/^(master|purchase|sale|inventory|report|permissions|audit-logs|database-backups)\./', $name)) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth', $middleware, $name);
            $guard = collect($middleware)->first(fn ($item) => str_starts_with($item, 'permission:') || $item === 'role:owner');
            $this->assertNotNull($guard, $name);
            if (str_starts_with($guard, 'permission:')) {
                $permission = AccessPermissions::forRoute($name);
                $this->assertNotNull($permission, $name);
                $this->assertStringStartsWith(substr($guard, strlen('permission:')).'.', $permission, $name);
            }
        }
    }

    public function test_owner_cannot_demote_their_own_account(): void
    {
        $owner = $this->user('owner');
        $this->user('owner');
        $this->actingAs($owner)->put(route('master.users.update', $owner), [
            'name' => $owner->name, 'email' => $owner->email, 'role' => 'kasir',
        ])->assertSessionHasErrors('role');
        $this->assertSame('owner', $owner->fresh()->role);
    }

    public function test_create_only_opname_redirects_to_an_accessible_detail_page(): void
    {
        $this->setPermissions('staf_gudang', ['inventory.opnames.view', 'inventory.opnames.create']);
        $location = Location::create(['name' => 'Gudang Tes']);
        $this->actingAs($this->user('staf_gudang'))->post(route('inventory.opnames.store'), [
            'location_id' => $location->id, 'opname_date' => today()->toDateString(),
        ])->assertRedirect(route('inventory.opnames.show', StockOpname::first()));
        $this->get(route('inventory.opnames.show', StockOpname::first()))->assertOk();
    }

    public function test_supplier_view_does_not_expose_purchase_history_without_order_permission(): void
    {
        $this->setPermissions('kasir', ['master.suppliers.view']);
        $supplier = Supplier::create(['name' => 'Supplier Tes']);
        $this->actingAs($this->user('kasir'))->get(route('master.suppliers.show', $supplier))
            ->assertOk()->assertDontSee('Riwayat Purchase Order');
    }
}
