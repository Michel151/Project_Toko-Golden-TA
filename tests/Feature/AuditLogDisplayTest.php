<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Location;
use App\Models\StockOpname;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_is_in_system_menu_and_remains_owner_only(): void
    {
        $owner=User::create(['name'=>'Owner','email'=>'audit-menu@example.com','password'=>'password','role'=>'owner']);
        $response=$this->actingAs($owner)->get(route('audit-logs.index'))->assertOk();
        $dom=new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath=new \DOMXPath($dom);
        $links=$xpath->query('//*[@id="sidebar"]//a[@href="'.route('audit-logs.index').'"]');
        $this->assertCount(1,$links);
        $button=$xpath->query('ancestor::ul[contains(@class,"nav-submenu")][1]/../preceding-sibling::li[1]/button',$links->item(0))->item(0);
        $this->assertNotNull($button);
        $this->assertSame('Sistem',trim($button->textContent));
        $this->assertSame('true',$button->getAttribute('aria-expanded'));
        $cashier=User::create(['name'=>'Kasir','email'=>'audit-menu-cashier@example.com','password'=>'password','role'=>'kasir']);
        $this->actingAs($cashier)->get(route('audit-logs.index'))->assertForbidden();
        $this->get(route('inventory.stock.index'))->assertOk()->assertDontSee('href="'.route('audit-logs.index').'"',false);
    }

    public function test_audit_detail_displays_readable_labels_and_relation_names(): void
    {
        $owner = User::create([
            'name' => 'Owner Golden',
            'email' => 'owner-audit@example.com',
            'password' => 'password',
            'role' => 'owner',
        ]);
        $location = Location::create(['name' => 'Gudang Utama']);

        AuditLog::create([
            'user_id' => $owner->id,
            'action' => 'created',
            'module' => 'Stock Opname',
            'description' => 'Membuat Stock Opname OPN-2026-000011',
            'auditable_type' => StockOpname::class,
            'auditable_id' => 11,
            'new_values' => [
                'opname_number' => 'OPN-2026-000011',
                'location_id' => $location->id,
                'opname_date' => '2026-09-26 00:00:00',
                'status' => 'draft',
                'created_by' => $owner->id,
                'id' => 11,
            ],
        ]);

        $this->actingAs($owner)
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertSee('Nomor Opname')
            ->assertSee('Gudang Utama')
            ->assertSee('Dibuat Oleh')
            ->assertSee('Owner Golden')
            ->assertSee('26 September 2026')
            ->assertSee('Data belum tersedia')
            ->assertDontSee('location_id')
            ->assertDontSee('created_by');
    }

    public function test_new_audit_entries_do_not_store_internal_id(): void
    {
        $owner = User::create([
            'name' => 'Owner Golden',
            'email' => 'owner-audit-id@example.com',
            'password' => 'password',
            'role' => 'owner',
        ]);
        $location = Location::create(['name' => 'Gudang Utama']);

        $this->actingAs($owner);

        $opname = StockOpname::create([
            'opname_number' => 'OPN-2026-000012',
            'location_id' => $location->id,
            'opname_date' => '2026-09-26',
            'status' => 'draft',
            'created_by' => $owner->id,
        ]);

        $log = AuditLog::where('auditable_type', StockOpname::class)
            ->where('auditable_id', $opname->id)
            ->where('action', 'created')
            ->firstOrFail();

        $this->assertArrayNotHasKey('id', $log->new_values);
    }
}
