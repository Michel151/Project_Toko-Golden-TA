<?php

namespace Tests\Feature;

use App\Models\{Location, StockOpname, User};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockOpnameNumberFormatTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner=User::create(['name'=>'Owner','email'=>'opname-number@example.com','password'=>'password','role'=>'owner']);
        $this->location=Location::create(['name'=>'Gudang Uji']);
        $this->actingAs($this->owner);
    }

    private function opname(string $number): StockOpname
    {
        return StockOpname::create([
            'opname_number'=>$number, 'location_id'=>$this->location->id,
            'opname_date'=>'2026-10-17', 'status'=>'draft', 'created_by'=>$this->owner->id,
        ]);
    }

    public function test_new_documents_increment_monthly_and_restart_without_changing_existing_numbers(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 10:00:00','Asia/Jakarta'));
        $legacy=$this->opname('OPN-2026-000999');
        foreach (['OPN/2026/10/000001','OPN/2026/10/000002'] as $number) {
            $this->post(route('inventory.opnames.store'),[
                'location_id'=>$this->location->id,'opname_date'=>'2026-10-17',
            ])->assertSessionHasNoErrors()->assertRedirect();
            $opname=StockOpname::latest('id')->first();
            $this->assertSame($number,$opname->opname_number);
        }
        $this->travelTo(Carbon::parse('2026-11-01 10:00:00','Asia/Jakarta'));
        $this->post(route('inventory.opnames.store'),[
            'location_id'=>$this->location->id,'opname_date'=>'2026-11-01',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $november=StockOpname::latest('id')->first();
        $this->assertSame('OPN/2026/11/000001',$november->opname_number);
        $this->assertSame('OPN/2026/11/000002',StockOpname::generateOpnameNumber());
        $this->assertSame('OPN/2026/10/000003',StockOpname::generateOpnameNumber(Carbon::parse('2026-10-17','Asia/Jakarta')));
        $this->assertSame('OPN/2027/01/000001',StockOpname::generateOpnameNumber(Carbon::parse('2027-01-01','Asia/Jakarta')));
        $this->assertSame('OPN-2026-000999',$legacy->fresh()->opname_number);
        $this->assertSame('OPN/2026/10/000002',$opname->fresh()->opname_number);
        $this->get(route('inventory.opnames.index'))->assertOk()->assertDontSee($legacy->opname_number)->assertSee($november->opname_number);
        $this->get(route('inventory.opnames.index', ['from'=>'2026-10-01', 'to'=>'2026-11-01']))->assertOk()->assertSee($legacy->opname_number)->assertSee($november->opname_number);
        $this->get(route('inventory.opnames.edit',$november))->assertOk()->assertSee($november->opname_number);
        $this->get(route('inventory.opnames.show',$november))->assertOk()->assertSee($november->opname_number);
        $this->get(route('inventory.opnames.show',$legacy))->assertOk()->assertSee($legacy->opname_number);
    }

    public function test_monthly_sequence_uses_the_highest_number_and_ignores_other_periods(): void
    {
        foreach (['OPN/2026/10/000100','OPN/2026/10/000003','OPN-2026-000999','OPN/2026/09/000999','OPN/2025/10/000999'] as $number) {
            $this->opname($number);
        }
        $this->assertSame('OPN/2026/10/000101',StockOpname::generateOpnameNumber(Carbon::parse('2026-10-17','Asia/Jakarta')));
        $this->assertSame('OPN/2026/12/000001',StockOpname::generateOpnameNumber(Carbon::parse('2026-12-01','Asia/Jakarta')));
    }

    public function test_jakarta_issue_time_determines_the_month_without_mutating_it(): void
    {
        $issuedAt=Carbon::parse('2026-12-31 17:01:00','UTC');
        $this->assertSame('OPN/2027/01/000001',StockOpname::generateOpnameNumber($issuedAt));
        $this->assertSame('2026-12-31 17:01:00 UTC',$issuedAt->format('Y-m-d H:i:s T'));
    }
}
