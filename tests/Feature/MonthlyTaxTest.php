<?php

namespace Tests\Feature;

use App\Models\{MonthlyTax, User, Sale, SaleItem, Product, Category, Unit};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Storage};
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class MonthlyTaxTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Jakarta')->setDate(2026, 10, 21)->setTime(10, 0));
        $this->owner = User::create(['name'=>'Owner Pajak', 'email'=>'tax@test.com', 'password'=>'password', 'role'=>'owner']);
        $this->actingAs($this->owner);
    }

    private function draft(string $month = '2026-10', string $amount = '50000.25'): MonthlyTax
    {
        $this->post(route('taxes.store'), compact('month', 'amount') + ['description'=>'Beban pajak '.$month])->assertSessionHasNoErrors();
        return MonthlyTax::where('month', $month)->firstOrFail();
    }

    private function confirm(MonthlyTax $tax): void
    {
        $this->post(route('taxes.confirm', $tax), ['version'=>$tax->fresh()->version, 'confirmation'=>1])->assertSessionHasNoErrors();
    }

    private function report(array $filters = [])
    {
        return $this->get(route('report.profit-loss', $filters ?: ['month'=>'2026-10']))->assertOk();
    }

    public function test_missing_draft_and_confirmed_zero_are_distinct(): void
    {
        $this->report()->assertViewHas('netProfit', null)->assertSee('Oktober 2026')->assertSee('Pajak belum dikonfirmasi');
        $tax = $this->draft(amount: '0');
        $this->get(route('taxes.show', $tax))->assertOk();
        $this->get(route('taxes.edit', $tax))->assertOk();
        $this->report()->assertViewHas('netProfit', null);
        $this->confirm($tax);
        $response = $this->report()->assertViewHas('netProfit', 0.0);
        $this->assertTrue($response->viewData('taxSummary')['complete']);
        $this->assertSame('0.00', $tax->fresh()->amount);
    }

    public function test_excel_header_only_adds_material_completeness_notes_when_needed(): void
    {
        Sale::create(['sale_number'=>'INV/INCOMPLETE', 'sale_date'=>'2026-10-01', 'status'=>'paid', 'payment_method'=>'tunai', 'total_amount'=>100000, 'created_by'=>$this->owner->id]);
        $data = $this->report()->original->getData();
        $html = view('report.pdf.profit-loss', $data)->render();
        $this->assertStringContainsString('Disajikan dalam Rupiah (Rp)', $html);
        $this->assertStringContainsString('HPP pada 1 transaksi penjualan belum lengkap', $html);
        $this->assertStringContainsString('Beban pajak belum dikonfirmasi untuk Oktober 2026', $html);
        $this->assertStringNotContainsString($data['notice'], $html);
        $this->get(route('report.profit-loss.pdf', ['month'=>'2026-10']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $excel = $this->get(route('report.profit-loss.excel', ['month'=>'2026-10']))->assertOk();
        $book = IOFactory::load($excel->baseResponse->getFile()->getPathname());
        $header = $book->getSheet(0)->getCell('A3')->getValue();
        $this->assertStringContainsString('Periode: 01/10/2026 s.d. 31/10/2026', $header);
        $this->assertStringContainsString('Disajikan dalam Rupiah (Rp)', $header);
        $this->assertStringContainsString('HPP pada 1 transaksi penjualan belum lengkap', $header);
        $this->assertStringContainsString('Beban pajak belum dikonfirmasi untuk Oktober 2026', $header);
        foreach (['moving average', 'owner', 'Draft belum dihitung', 'HPP historis'] as $technicalText) {
            $this->assertStringNotContainsString($technicalText, $header);
        }
        $this->assertSame('Belum lengkap', $book->getSheet(0)->getCell('B'.$book->getSheet(0)->getHighestDataRow())->getValue());
        $book->disconnectWorksheets();
    }

    public function test_manual_tax_changes_only_after_tax_profit_and_all_exports_match(): void
    {
        $category = Category::create(['name'=>'Kategori']); $unit = Unit::create(['name'=>'Botol']);
        $product = Product::create(['name'=>'Barang', 'category_id'=>$category->id, 'buy_unit_id'=>$unit->id, 'buy_price'=>9999, 'sell_price_wholesale'=>65000]);
        $sale = Sale::create(['sale_number'=>'INV/TAX', 'sale_date'=>'2026-10-01', 'status'=>'paid', 'payment_method'=>'tunai', 'total_amount'=>200000, 'created_by'=>$this->owner->id]);
        SaleItem::create(['sale_id'=>$sale->id, 'product_id'=>$product->id, 'quantity'=>2, 'unit_price'=>100000, 'subtotal'=>200000, 'cost_price'=>50000, 'cost_subtotal'=>100000, 'sale_type'=>'ecer']);
        $before = DB::table('sale_items')->get()->toJson();
        $ledger = DB::table('inventory_cost_entries')->get()->toJson();
        $tax = $this->draft(); $this->confirm($tax);
        $response = $this->report()->assertViewHas('cost', 100000.0)->assertViewHas('operatingProfit', 100000.0)->assertViewHas('netProfit', 49999.75);
        $pdf = $this->get(route('report.profit-loss.pdf', ['month'=>'2026-10']))->assertOk()->assertHeader('Content-Type','application/pdf');
        $html = view('report.pdf.profit-loss', $response->original->getData())->render();
        $this->assertStringContainsString('Laba (Rugi) Bersih', $html);
        $this->assertStringContainsString('49.999,75', $html);
        $this->assertStringContainsString('Disajikan dalam Rupiah (Rp)', $html);
        $this->assertStringNotContainsString($response->viewData('notice'), $html);
        $this->assertStringNotContainsString('Catatan: Beban pajak belum dikonfirmasi', $html);
        $this->assertStringNotContainsString('Catatan: HPP pada', $html);
        $excel = $this->get(route('report.profit-loss.excel', ['month'=>'2026-10']))->assertOk();
        $book = IOFactory::load($excel->baseResponse->getFile()->getPathname());
        $sheet = $book->getSheet(0); $last = $sheet->getHighestDataRow();
        $this->assertSame('Periode: 01/10/2026 s.d. 31/10/2026 | Disajikan dalam Rupiah (Rp)', $sheet->getCell('A3')->getValue());
        $this->assertSame('Laba (Rugi) Bersih', $sheet->getCell('A'.$last)->getValue());
        $this->assertEquals(49999.75, $sheet->getCell('B'.$last)->getValue());
        $this->assertEquals(-50000.25, $sheet->getCell('B'.($last-1))->getValue());
        $this->assertSame(['Laba Rugi','Rekonsiliasi Persediaan'], $book->getSheetNames());
        $book->disconnectWorksheets();
        $this->assertSame($before, DB::table('sale_items')->get()->toJson());
        $this->assertSame($ledger, DB::table('inventory_cost_entries')->get()->toJson());
        if (getenv('CAPTURE_TAX_UI') === '1') {
            $dir = storage_path('framework/testing/taxes'); if (!is_dir($dir)) mkdir($dir,0777,true);
            file_put_contents($dir.'/report.html', $response->getContent());
            file_put_contents($dir.'/report.pdf', $pdf->getContent());
            copy($excel->baseResponse->getFile()->getPathname(), $dir.'/report.xlsx');
            foreach (['index'=>route('taxes.index'), 'create'=>route('taxes.create'), 'show'=>route('taxes.show',$tax), 'edit'=>route('taxes.edit',$tax)] as $name=>$url) {
                file_put_contents($dir.'/'.$name.'.html', $this->get($url)->assertOk()->getContent());
            }
        }
        $sale->items()->update(['cost_subtotal'=>-1]);
        $this->report()->assertViewHas('netProfit', null);
    }

    public function test_corrections_require_reason_reconfirmation_and_preserve_history(): void
    {
        $tax = $this->draft(); $this->confirm($tax);
        $this->put(route('taxes.update',$tax), ['version'=>2,'amount'=>'60000','description'=>'Koreksi'])->assertSessionHasErrors('correction_reason');
        $this->assertSame('confirmed',$tax->fresh()->status);
        $this->put(route('taxes.update',$tax), ['version'=>2,'amount'=>'60000','description'=>'Koreksi','correction_reason'=>'Perhitungan disesuaikan'])->assertSessionHasNoErrors();
        $this->assertSame('draft',$tax->fresh()->status);
        $this->report()->assertViewHas('netProfit', null);
        $this->assertSame('50000.25',$tax->revisions()->where('action','confirm')->first()->snapshot['amount']);
        $this->confirm($tax);
        $this->report()->assertViewHas('netProfit', -60000.0);
        $this->assertSame(4,$tax->revisions()->count());
        $this->assertDatabaseHas('audit_logs',['module'=>'monthly_tax','action'=>'update']);
    }

    public function test_duplicate_period_stale_edits_repeated_confirm_and_period_changes_are_blocked(): void
    {
        $tax = $this->draft();
        $this->post(route('taxes.store'), ['month'=>'2026-10','amount'=>1,'description'=>'Ganda'])->assertSessionHasErrors('month');
        $this->confirm($tax);
        $this->post(route('taxes.confirm',$tax), ['version'=>1,'confirmation'=>1])->assertSessionHasErrors('tax');
        $this->post(route('taxes.confirm',$tax), ['version'=>2,'confirmation'=>1])->assertStatus(409);
        $this->put(route('taxes.update',$tax), ['version'=>1,'amount'=>1,'description'=>'Lama'])->assertSessionHasErrors('tax');
        $this->put(route('taxes.update',$tax), ['version'=>2,'month'=>'2026-09','amount'=>1,'description'=>'Ganti'])->assertSessionHasErrors('month');
        $this->assertSame(1, MonthlyTax::count());
    }

    public function test_quarterly_and_yearly_require_every_month_without_double_counting(): void
    {
        for ($month=1; $month<=12; $month++) {
            $tax = $this->draft(sprintf('2026-%02d',$month), '100.25');
            if ($month !== 3) $this->confirm($tax);
        }
        $filter = ['period_type'=>'quarterly','year'=>2026,'quarter'=>1];
        $this->report($filter)->assertViewHas('netProfit',null)->assertSee('Maret 2026');
        $this->report(['period_type'=>'yearly','year'=>2026])->assertViewHas('netProfit',null);
        $this->confirm(MonthlyTax::where('month','2026-03')->first());
        $this->report($filter)->assertViewHas('netProfit',-300.75);
        $this->report(['period_type'=>'yearly','year'=>2026])->assertViewHas('netProfit',-1203.0);
        $this->get(route('taxes.index',$filter))->assertOk()->assertViewHas('taxes',fn($rows)=>$rows->total()===3);
        $this->get(route('taxes.index'))->assertOk()->assertViewHas('month','2026-10');
    }

    public function test_owner_only_all_routes_and_private_proofs(): void
    {
        Storage::fake('local');
        $this->post(route('taxes.store'), ['month'=>'2026-10','amount'=>0,'description'=>'Dokumen', 'proof'=>UploadedFile::fake()->create('bukti.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors();
        $tax = MonthlyTax::first();
        $this->get(route('taxes.proof',$tax))->assertOk();
        $revision = $tax->revisions()->first();
        $this->get(route('taxes.proof',[$tax,'revision'=>$revision->id]))->assertOk();
        $this->get(route('taxes.proof',[$tax,'revision'=>99999]))->assertNotFound();
        foreach (['kasir','staf_gudang'] as $role) {
            $this->actingAs(User::create(['name'=>$role,'email'=>$role.'@tax.test','password'=>'password','role'=>$role]));
            foreach (['taxes.index'=>[], 'taxes.create'=>[], 'taxes.show'=>[$tax], 'taxes.edit'=>[$tax], 'taxes.proof'=>[$tax]] as $route=>$args) $this->get(route($route,$args))->assertForbidden();
            $this->post(route('taxes.store'),[])->assertForbidden();
            $this->put(route('taxes.update',$tax),[])->assertForbidden();
            $this->post(route('taxes.confirm',$tax),[])->assertForbidden();
        }
    }

    public function test_invalid_nominals_periods_and_unconfirmed_input_are_rejected(): void
    {
        foreach (['-1','1000.251','1,000','1e3','1000000000000'] as $amount) $this->post(route('taxes.store'),['month'=>'2026-10','amount'=>$amount,'description'=>'Uji'])->assertSessionHasErrors('amount');
        $this->post(route('taxes.store'),['month'=>'2026-13','amount'=>0,'description'=>'Uji'])->assertSessionHasErrors('month');
        $tax = $this->draft();
        $this->post(route('taxes.confirm',$tax),['version'=>1])->assertSessionHasErrors('confirmation');
        $this->assertSame('draft',$tax->fresh()->status);
    }

    public function test_replacing_proof_keeps_previous_revision_document_private_and_available(): void
    {
        Storage::fake('local');
        $this->post(route('taxes.store'), ['month'=>'2026-10','amount'=>0,'description'=>'Awal',
            'proof'=>UploadedFile::fake()->create('awal.pdf',20,'application/pdf')])->assertSessionHasNoErrors();
        $tax = MonthlyTax::first(); $oldPath = $tax->proof_path; $revision = $tax->revisions()->first();
        $this->confirm($tax);
        $this->put(route('taxes.update',$tax), ['version'=>2,'amount'=>1,'description'=>'Koreksi','correction_reason'=>'Dokumen diperbarui',
            'proof'=>UploadedFile::fake()->create('baru.pdf',20,'application/pdf')])->assertSessionHasNoErrors();
        Storage::disk('local')->assertExists($oldPath);
        $this->assertNotSame($oldPath,$tax->fresh()->proof_path);
        $this->get(route('taxes.proof',[$tax,'revision'=>$revision->id]))->assertOk();
        $other = $this->draft('2026-09');
        $this->get(route('taxes.proof',[$other,'revision'=>$revision->id]))->assertNotFound();
        $this->get(route('taxes.index'))->assertOk();
        $this->get(route('taxes.create'))->assertOk();
        $this->get(route('taxes.show',$tax))->assertOk();
        $this->get(route('taxes.edit',$tax))->assertOk();
    }
}
