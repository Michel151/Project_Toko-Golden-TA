<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AccessPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RemovedGrossProfitReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_standalone_gross_profit_report_and_exports_are_removed(): void
    {
        $this->actingAs(User::create(['name'=>'Owner','email'=>'removed-report@test.com','password'=>'password','role'=>'owner']));
        foreach (['','/pdf','/excel'] as $suffix) $this->get('/report/gross-profit'.$suffix)->assertNotFound();
        foreach (['','.pdf','.excel'] as $suffix) $this->assertFalse(Route::has('report.gross-profit'.$suffix));
        $this->assertArrayNotHasKey('report.gross-profit', AccessPermissions::features());
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Laporan Laba Kotor')->assertSee('Laporan Laba Rugi');
        $this->get(route('permissions.index'))->assertOk()->assertDontSee('Laporan Laba Kotor');
    }

    public function test_gross_profit_remains_in_profit_loss_report_and_exports(): void
    {
        $this->actingAs(User::create(['name'=>'Owner','email'=>'remaining-report@test.com','password'=>'password','role'=>'owner']));
        $this->get(route('report.profit-loss'))->assertOk()->assertSee('Laba Kotor')->assertViewHas('grossProfit',0.0);
        $this->get(route('report.profit-loss.pdf'))->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->get(route('report.profit-loss.excel'))->assertOk();
    }
}
