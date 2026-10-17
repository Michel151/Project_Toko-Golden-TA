<?php

namespace Tests\Feature;

use App\Models\{Sale, User};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleNumberFormatTest extends TestCase
{
    use RefreshDatabase;

    private function sale(string $number): Sale
    {
        $user=User::firstOrCreate(['email'=>'invoice@example.com'],[
            'name'=>'Owner', 'password'=>'password', 'role'=>'owner',
        ]);
        return Sale::create([
            'sale_number'=>$number, 'sale_date'=>'2026-10-17', 'sale_time'=>'10:00:00',
            'status'=>'paid', 'total_amount'=>1000, 'payment_method'=>'tunai', 'created_by'=>$user->id,
        ]);
    }

    public function test_new_number_uses_jakarta_year_month_and_six_digit_sequence(): void
    {
        $this->travelTo(Carbon::parse('2026-10-17 10:00:00','Asia/Jakarta'));
        $this->assertSame('INV/2026/10/000001',Sale::generateSaleNumber());
        $this->sale(Sale::generateSaleNumber());
        $this->assertSame('INV/2026/10/000002',Sale::generateSaleNumber());
    }

    public function test_monthly_sequence_resets_without_changing_existing_numbers(): void
    {
        $legacy=$this->sale('INV-2026-000006');
        $date=Carbon::parse('2026-10-17 10:00:00','Asia/Jakarta');
        $this->assertSame('INV/2026/10/000001',Sale::generateSaleNumber($date));
        $october=$this->sale('INV/2026/10/000007');
        $this->assertSame('INV/2026/10/000008',Sale::generateSaleNumber($date));
        $this->assertSame('INV/2026/11/000001',Sale::generateSaleNumber($date->copy()->addMonth()));
        $this->sale('INV/2026/11/000001');
        $this->assertSame('INV/2026/11/000002',Sale::generateSaleNumber($date->copy()->addMonth()));
        $this->assertSame('INV/2026/10/000008',Sale::generateSaleNumber($date));
        $this->assertSame('INV/2027/01/000001',Sale::generateSaleNumber($date->copy()->setDate(2027,1,1)));
        $this->assertSame('INV-2026-000006',$legacy->fresh()->sale_number);
        $this->assertSame('INV/2026/10/000007',$october->fresh()->sale_number);
    }

    public function test_highest_number_is_used_even_when_records_are_inserted_out_of_order(): void
    {
        $this->sale('INV/2026/10/000100');
        $this->sale('INV/2026/10/000003');
        $this->sale('INV-2026-000200');
        $this->sale('INV-2026-000005');
        $this->sale('INV/2026/09/000999');
        $this->sale('INV/2025/10/000999');
        $this->assertSame('INV/2026/10/000101',Sale::generateSaleNumber(Carbon::parse('2026-10-17','Asia/Jakarta')));
    }

    public function test_number_uses_the_same_captured_issue_time_in_jakarta(): void
    {
        $issuedAt=Carbon::parse('2026-12-31 17:01:00','UTC');
        $this->assertSame('INV/2027/01/000001',Sale::generateSaleNumber($issuedAt));
        $this->assertSame('2026-12-31 17:01:00 UTC',$issuedAt->format('Y-m-d H:i:s T'));
    }

    public function test_existing_and_new_numbers_are_visible_on_list_detail_and_receipt(): void
    {
        $legacy=$this->sale('INV-2026-000006');
        $sale=$this->sale('INV/2026/10/000007');
        $this->actingAs($sale->createdBy);
        $this->get(route('sale.sales.index'))->assertOk()->assertSee($legacy->sale_number)->assertSee($sale->sale_number);
        $this->get(route('sale.sales.show',$sale))->assertOk()->assertSee($sale->sale_number);
        $this->get(route('sale.sales.print',['sale'=>$sale,'popup'=>1]))->assertOk()->assertSee($sale->sale_number);
        $pdf=$this->get(route('sale.sales.print',$sale))->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->assertStringContainsString('Nota-INV-2026-10-000007.pdf',$pdf->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-',$pdf->getContent());
    }
}
