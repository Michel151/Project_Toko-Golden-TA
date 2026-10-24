<?php

namespace Tests\Feature;

use App\Models\{Category, Expense, Product, ProductUnit, Sale, SaleItem, Unit, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-05 10:00:00', 'Asia/Jakarta'));
        $this->owner = User::create(['name' => 'Owner Uji', 'email' => 'expense-owner@example.com', 'password' => 'password', 'role' => 'owner']);
        $this->actingAs($this->owner);
        Storage::fake('local');
    }

    private function fields(array $overrides = []): array
    {
        return array_merge(['recognized_date' => '2026-10-31', 'category' => 'electricity', 'description' => 'Tagihan listrik Oktober',
            'amount' => '500.25', 'payment_status' => 'unpaid', 'submission_token' => (string) str()->uuid()], $overrides);
    }

    private function expense(array $overrides = []): Expense
    {
        $data = $this->fields();
        unset($data['payment_status']);
        return Expense::create(array_merge($data, ['created_by' => $this->owner->id], $overrides));
    }

    private function report()
    {
        return $this->get(route('report.profit-loss', ['month' => '2026-10']));
    }

    public function test_only_owner_can_access_every_expense_action_and_report_export(): void
    {
        $expense = $this->expense();
        foreach (['kasir', 'staf_gudang'] as $role) {
            $user = User::create(['name' => $role, 'email' => $role.'-expenses@example.com', 'password' => 'password', 'role' => $role]);
            $this->actingAs($user);
            foreach (['expenses.index', 'expenses.create', 'report.profit-loss', 'report.profit-loss.pdf', 'report.profit-loss.excel'] as $route) $this->get(route($route))->assertForbidden();
            foreach (['expenses.show', 'expenses.edit', 'expenses.proof'] as $route) $this->get(route($route, $expense))->assertForbidden();
            foreach (['expenses.confirm', 'expenses.pay', 'expenses.void'] as $route) $this->post(route($route, $expense), ['version' => 1])->assertForbidden();
            $this->post(route('expenses.store'), $this->fields())->assertForbidden();
            $this->put(route('expenses.update', $expense), $this->fields())->assertForbidden();
        }
    }

    public function test_draft_is_saved_once_with_exact_decimals_and_no_report_effect(): void
    {
        $data = $this->fields();
        $this->post(route('expenses.store'), $data)->assertRedirect();
        $this->post(route('expenses.store'), $data)->assertRedirect();
        $this->assertSame(1, Expense::count());
        $this->assertSame('500.25', Expense::first()->amount);
        $this->report()->assertOk()->assertViewHas('expenseTotal', 0.0)->assertViewHas('draftCount', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'create', 'module' => 'expense']);
    }

    public function test_recognition_date_controls_report_and_payment_never_duplicates_expense(): void
    {
        $expense = $this->expense();
        $this->post(route('expenses.confirm', $expense), ['version' => 1])->assertRedirect();
        $this->report()->assertViewHas('expenseTotal', 500.25)->assertViewHas('operatingProfit', -500.25);
        $this->post(route('expenses.pay', $expense), ['version' => 2, 'paid_date' => '2026-11-05', 'payment_method' => 'transfer'])->assertRedirect();
        $this->report()->assertViewHas('expenseTotal', 500.25);
        $this->get(route('report.profit-loss', ['month' => '2026-11']))->assertViewHas('expenseTotal', 0.0);
        $this->post(route('expenses.pay', $expense), ['version' => 2, 'paid_date' => '2026-11-05', 'payment_method' => 'cash'])->assertSessionHasErrors('expense');
        $this->assertSame(1, Expense::count());
        $this->assertSame('transfer', $expense->fresh()->payment_method);
    }

    public function test_confirmed_records_cannot_be_edited_and_void_keeps_history(): void
    {
        $expense = $this->expense(['status' => 'confirmed']);
        $this->get(route('expenses.edit', $expense))->assertStatus(409);
        $this->put(route('expenses.update', $expense), $this->fields(['version' => 1, 'amount' => '1']))->assertStatus(409);
        $this->post(route('expenses.void', $expense), ['version' => 1])->assertSessionHasErrors('void_reason');
        $this->post(route('expenses.void', $expense), ['version' => 1, 'void_reason' => 'Salah pencatatan'])->assertRedirect();
        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'status' => 'void', 'amount' => '500.25']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'void', 'module' => 'expense']);
        $this->report()->assertViewHas('expenseTotal', 0.0);
    }

    public function test_stale_edit_and_repeated_confirmation_are_rejected(): void
    {
        $expense = $this->expense();
        $this->put(route('expenses.update', $expense), $this->fields(['version' => 1, 'amount' => '1000.25']))->assertRedirect();
        $this->put(route('expenses.update', $expense), $this->fields(['version' => 1, 'amount' => '9']))->assertSessionHasErrors('expense');
        $this->post(route('expenses.confirm', $expense), ['version' => 2])->assertRedirect();
        $this->post(route('expenses.confirm', $expense), ['version' => 2])->assertSessionHasErrors('expense');
        $this->assertSame('1000.25', $expense->fresh()->amount);
    }

    public function test_validation_rejects_ambiguous_amounts_invalid_categories_and_payment_details(): void
    {
        foreach (['1.000,25', '1,000.25', '-1', '0', '1000.251', '1e3'] as $amount) $this->post(route('expenses.store'), $this->fields(['amount' => $amount]))->assertSessionHasErrors('amount');
        $this->post(route('expenses.store'), $this->fields(['category' => 'inventory']))->assertSessionHasErrors('category');
        $this->post(route('expenses.store'), $this->fields(['payment_status' => 'paid']))->assertSessionHasErrors(['paid_date', 'payment_method']);
        $this->post(route('expenses.store'), $this->fields(['payment_status' => 'paid', 'paid_date' => '2026-11-06', 'payment_method' => 'cash']))->assertSessionHasErrors('paid_date');
        $this->post(route('expenses.store'), $this->fields(['paid_date' => '2026-11-01', 'payment_method' => 'cash']))->assertRedirect();
        $this->assertNull(Expense::first()->paid_date);
    }

    public function test_proofs_are_private_downloadable_and_executable_files_are_rejected(): void
    {
        $this->post(route('expenses.store'), $this->fields(['proof' => UploadedFile::fake()->create('tagihan.pdf', 10, 'application/pdf')]))->assertRedirect();
        $expense = Expense::firstOrFail();
        Storage::disk('local')->assertExists($expense->proof_path);
        $this->get(route('expenses.proof', $expense))->assertOk()->assertDownload();
        $this->post(route('expenses.store'), $this->fields(['proof' => UploadedFile::fake()->create('shell.php', 10, 'application/x-php')]))->assertSessionHasErrors('proof');
    }

    public function test_historical_hpp_and_report_exports_match_and_missing_hpp_blocks_profit(): void
    {
        $unit = Unit::create(['name' => 'Pcs']);
        $category = Category::create(['name' => 'Kategori']);
        $product = Product::create(['name' => 'Barang', 'category_id' => $category->id, 'buy_unit_id' => $unit->id, 'buy_price' => 9999, 'sell_price_wholesale' => 200]);
        $productUnit = ProductUnit::create(['product_id' => $product->id, 'unit_id' => $unit->id, 'conversion_to_base' => 1, 'buy_price' => 9999, 'sell_price' => 200, 'is_base_unit' => true]);
        $sale = Sale::create(['sale_number' => 'INV-EXPENSE', 'sale_date' => '2026-10-10', 'status' => 'paid', 'payment_method' => 'tunai', 'total_amount' => 2000, 'created_by' => $this->owner->id]);
        SaleItem::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_unit_id' => $productUnit->id, 'quantity' => 10, 'unit_price' => 200, 'subtotal' => 2000, 'cost_price' => 100, 'cost_subtotal' => 1000, 'sale_type' => 'grosir']);
        $this->expense(['status' => 'confirmed']);
        $this->expense(['status' => 'confirmed', 'recognized_date' => '2026-09-30']);
        $this->report()->assertViewHas('revenue', 2000.0)->assertViewHas('cost', 1000.0)->assertViewHas('grossProfit', 1000.0)->assertViewHas('operatingProfit', 499.75);
        $filters = ['period_type' => 'monthly', 'month' => '2026-10'];
        $this->get(route('report.profit-loss.pdf', $filters))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $response = $this->get(route('report.profit-loss.excel', $filters))->assertOk();
        $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $sheet = $book->getActiveSheet();
        $this->assertEquals(499.75, $sheet->getCell('B'.($sheet->getHighestDataRow()-2))->getValue());
        $this->assertSame('Belum lengkap', $sheet->getCell('B'.$sheet->getHighestDataRow())->getValue());
        $book->disconnectWorksheets();
        $sale->items()->update(['cost_subtotal' => 0]);
        $this->report()->assertViewHas('incompleteCount', 1)->assertViewHas('operatingProfit', null)->assertSee('Laba belum dapat ditentukan');
    }

    public function test_report_totals_cover_all_pages_and_filters_are_validated(): void
    {
        for ($i = 0; $i < 27; $i++) $this->expense(['status' => 'confirmed', 'amount' => '10.25']);
        $this->get(route('report.profit-loss', ['month' => '2026-10', 'page' => 2]))
            ->assertViewHas('expenseTotal', 276.75)->assertViewHas('expenses', fn ($rows) => $rows->count() === 2);
        $this->get(route('expenses.index', ['month' => '2026-10', 'status' => 'confirmed', 'payment' => 'unpaid']))->assertOk()->assertViewHas('total', 276.75);
        $this->get(route('report.profit-loss', ['month' => '2026-13']))->assertSessionHasErrors('month');
    }

    public function test_month_filter_covers_boundaries_leap_year_and_keeps_other_filters_and_pagination(): void
    {
        foreach (['2024-02-01', '2024-02-29'] as $date) {
            $this->expense(['recognized_date' => $date, 'status' => 'confirmed', 'amount' => '10.25']);
        }
        foreach (['2024-01-31', '2024-03-01', '2025-02-01'] as $date) {
            $this->expense(['recognized_date' => $date, 'status' => 'confirmed', 'amount' => '99']);
        }
        $this->expense(['recognized_date' => '2024-02-15', 'status' => 'draft']);
        $this->expense(['recognized_date' => '2024-02-15', 'status' => 'confirmed', 'category' => 'salary']);
        $this->get(route('expenses.index', ['month' => '2024-02', 'status' => 'confirmed', 'category' => 'electricity']))
            ->assertOk()->assertViewHas('total', 20.5)->assertViewHas('expenses', fn ($rows) => $rows->total() === 2)
            ->assertSee('type="month"', false)->assertDontSee('name="from"', false)->assertDontSee('name="to"', false);
        $this->get(route('expenses.index', ['month' => '2024-13']))->assertSessionHasErrors('month');
        $this->get(route('expenses.index', ['month' => '2024-02-01']))->assertSessionHasErrors('month');
        $this->get(route('expenses.index', ['month' => '']))->assertSessionHasErrors('month');
        for ($i = 0; $i < 27; $i++) $this->expense(['recognized_date' => '2024-02-10', 'status' => 'confirmed', 'category' => 'water', 'amount' => '1']);
        $page = $this->get(route('expenses.index', ['month' => '2024-02', 'category' => 'water', 'page' => 2]));
        $page->assertViewHas('total', 27)->assertViewHas('expenses', fn ($rows) => $rows->count() === 2 && str_contains($rows->url(1), 'month=2024-02'));
    }

    public function test_expense_list_defaults_to_current_jakarta_month_and_allows_explicit_selection(): void
    {
        $this->expense(['recognized_date' => '2026-11-01', 'status' => 'confirmed', 'amount' => '10.25']);
        $this->expense(['recognized_date' => '2026-10-31', 'status' => 'confirmed', 'amount' => '99']);
        $this->get(route('expenses.index'))->assertOk()
            ->assertViewHas('filters', fn ($filters) => $filters['month'] === '2026-11')
            ->assertViewHas('total', 10.25)->assertViewHas('expenses', fn ($rows) => $rows->total() === 1)
            ->assertSee('value="2026-11"', false);
        $this->get(route('expenses.index', ['month' => '2026-10']))->assertViewHas('total', 99);
        $this->get(route('expenses.index', ['period_type' => 'yearly', 'year' => 2026]))->assertViewHas('total', 109.25);
        $this->travelTo(\Carbon\Carbon::parse('2026-11-30 18:00:00', 'UTC'));
        $this->get(route('expenses.index'))->assertOk()
            ->assertViewHas('filters', fn ($filters) => $filters['month'] === '2026-12');
    }

    public function test_profit_loss_defaults_to_current_jakarta_month_and_uses_complete_calendar_periods(): void
    {
        $this->get(route('report.profit-loss'))->assertOk()->assertViewHas('periodType', 'monthly')
            ->assertViewHas('month', '2026-11')->assertViewHas('from', '2026-11-01')->assertViewHas('to', '2026-11-30')
            ->assertDontSee('type="date"', false);
        $this->get(route('report.profit-loss', ['month' => '2024-02']))->assertViewHas('from', '2024-02-01')->assertViewHas('to', '2024-02-29');
        foreach ([1 => ['2024-01-01', '2024-03-31'], 2 => ['2024-04-01', '2024-06-30'], 3 => ['2024-07-01', '2024-09-30'], 4 => ['2024-10-01', '2024-12-31']] as $quarter => [$from, $to]) {
            $this->get(route('report.profit-loss', ['period_type' => 'quarterly', 'year' => 2024, 'quarter' => $quarter]))
                ->assertOk()->assertViewHas('from', $from)->assertViewHas('to', $to)->assertViewHas('periodLabel', 'Kuartal '.$quarter.' 2024');
        }
        $this->get(route('report.profit-loss', ['period_type' => 'yearly', 'year' => 2024]))
            ->assertViewHas('from', '2024-01-01')->assertViewHas('to', '2024-12-31');
        $this->travelTo(\Carbon\Carbon::parse('2026-11-30 18:00:00', 'UTC'));
        $this->get(route('report.profit-loss'))->assertViewHas('month', '2026-12');
    }

    public function test_quarterly_and_yearly_totals_exports_and_pagination_use_the_same_period(): void
    {
        foreach (['2024-01-01', '2024-03-31', '2024-04-01', '2024-12-31', '2025-01-01'] as $date) $this->expense(['recognized_date' => $date, 'status' => 'confirmed', 'amount' => '10.25']);
        $filters = ['period_type' => 'quarterly', 'year' => 2024, 'quarter' => 1];
        $this->get(route('report.profit-loss', $filters))->assertViewHas('expenseTotal', 20.5)->assertViewHas('operatingProfit', -20.5)
            ->assertSee(route('report.profit-loss.excel', $filters));
        foreach (['quarterly' => 20.5, 'yearly' => 41.0] as $type => $total) {
            $parameters = ['period_type' => $type, 'year' => 2024, 'quarter' => 1];
            $page = $this->get(route('report.profit-loss', $parameters))->assertViewHas('expenseTotal', $total);
            $this->get(route('report.profit-loss.pdf', $parameters))->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $html = view('report.pdf.profit-loss', $page->original->getData())->render();
            $this->assertStringContainsString($type === 'quarterly' ? 'Kuartal 1 2024' : 'Tahun 2024', $html);
            $response = $this->get(route('report.profit-loss.excel', $parameters))->assertOk();
            $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
            $sheet = $book->getActiveSheet();
            $this->assertEquals(-$total, $sheet->getCell('B'.($sheet->getHighestDataRow()-2))->getValue());
            $this->assertSame('Belum lengkap', $sheet->getCell('B'.$sheet->getHighestDataRow())->getValue());
            $book->disconnectWorksheets();
        }
        for ($i = 0; $i < 27; $i++) $this->expense(['recognized_date' => '2024-02-29', 'status' => 'confirmed', 'amount' => '1']);
        $this->get(route('report.profit-loss', $filters + ['page' => 2]))->assertViewHas('expenseTotal', 47.5)
            ->assertViewHas('expenses', fn ($rows) => $rows->total() === 29 && str_contains($rows->url(1), 'period_type=quarterly') && str_contains($rows->url(1), 'quarter=1'));
        foreach ([['period_type' => 'weekly'], ['period_type' => 'quarterly', 'quarter' => 0], ['period_type' => 'quarterly', 'quarter' => 5], ['period_type' => 'yearly', 'year' => 0], ['period_type' => 'yearly', 'year' => 'invalid']] as $invalid) {
            $field = isset($invalid['year']) ? 'year' : (isset($invalid['quarter']) ? 'quarter' : 'period_type');
            $this->get(route('report.profit-loss', $invalid))->assertSessionHasErrors($field);
        }
    }

    public function test_expense_quarterly_and_yearly_filters_match_report_and_never_modify_records(): void
    {
        foreach (['2024-01-01', '2024-03-31', '2024-04-01', '2024-12-31', '2025-01-01'] as $date) {
            $this->expense(['recognized_date' => $date, 'status' => 'confirmed', 'amount' => '10.25']);
        }
        $this->expense(['recognized_date' => '2024-02-15', 'status' => 'draft']);
        $before = Expense::orderBy('id')->get()->toArray();
        foreach ([1 => ['2024-01-01', '2024-03-31', 20.5], 2 => ['2024-04-01', '2024-06-30', 10.25], 3 => ['2024-07-01', '2024-09-30', 0], 4 => ['2024-10-01', '2024-12-31', 10.25]] as $quarter => [$from, $to, $total]) {
            $filters = ['period_type' => 'quarterly', 'year' => 2024, 'quarter' => $quarter, 'status' => 'confirmed'];
            $this->get(route('expenses.index', $filters))->assertOk()->assertViewHas('from', $from)->assertViewHas('to', $to)->assertViewHas('total', $total);
            $this->get(route('report.profit-loss', $filters))->assertViewHas('expenseTotal', $total)
                ->assertSee(route('expenses.index', $filters));
        }
        $this->get(route('expenses.index', ['period_type' => 'yearly', 'year' => 2024, 'status' => 'confirmed']))
            ->assertOk()->assertViewHas('total', 41.0)->assertViewHas('expenses', fn ($rows) => $rows->total() === 4);
        $this->assertSame($before, Expense::orderBy('id')->get()->toArray());
    }

    public function test_expense_periods_preserve_other_filters_pagination_and_validate_parameters(): void
    {
        for ($i = 0; $i < 27; $i++) $this->expense(['recognized_date' => '2024-02-29', 'status' => 'confirmed', 'category' => 'water', 'description' => 'Air kantor', 'amount' => '1']);
        $this->expense(['recognized_date' => '2024-02-10', 'status' => 'confirmed', 'category' => 'water', 'description' => 'Air gudang']);
        $this->expense(['recognized_date' => '2024-02-10', 'status' => 'confirmed', 'category' => 'water', 'description' => 'Air kantor', 'paid_date' => '2024-02-10', 'payment_method' => 'cash']);
        $filters = ['period_type' => 'quarterly', 'year' => 2024, 'quarter' => 1, 'status' => 'confirmed', 'payment' => 'unpaid', 'category' => 'water', 'q' => 'kantor'];
        $this->get(route('expenses.index', $filters + ['page' => 2]))->assertOk()->assertViewHas('total', 27)
            ->assertViewHas('expenses', fn ($rows) => $rows->total() === 27 && $rows->count() === 2 && str_contains($rows->url(1), 'period_type=quarterly') && str_contains($rows->url(1), 'quarter=1') && str_contains($rows->url(1), 'payment=unpaid'));
        foreach ([['period_type' => 'weekly'], ['period_type' => 'quarterly', 'quarter' => 0], ['period_type' => 'quarterly', 'quarter' => 5], ['period_type' => 'yearly', 'year' => 0]] as $invalid) {
            $field = isset($invalid['year']) ? 'year' : (isset($invalid['quarter']) ? 'quarter' : 'period_type');
            $this->get(route('expenses.index', $invalid))->assertSessionHasErrors($field);
        }
    }

    public function test_expense_numbers_restart_monthly_and_yearly_and_do_not_follow_recognition_edits(): void
    {
        $first = $this->expense();
        $second = $this->expense(['status' => 'void']);
        $this->assertSame('BEB/2026/11/000001', $first->number);
        $this->assertSame('BEB/2026/11/000002', $second->number);
        $this->put(route('expenses.update', $first), $this->fields(['version' => 1, 'recognized_date' => '2026-01-01']))->assertRedirect();
        $this->assertSame('BEB/2026/11/000001', $first->fresh()->number);
        $this->assertSame('BEB/2026/11/000003', $this->expense()->number);
        $this->travelTo(\Carbon\Carbon::parse('2026-12-01 00:00:00', 'Asia/Jakarta'));
        $this->assertSame('BEB/2026/12/000001', $this->expense()->number);
        $this->travelTo(\Carbon\Carbon::parse('2026-12-31 18:00:00', 'UTC'));
        $this->assertSame('BEB/2027/01/000001', $this->expense()->number);
        $this->assertSame('BEB/2026/11/000001', $first->fresh()->number);
    }

    public function test_expense_number_reservation_rolls_back_with_transaction_and_duplicates_are_not_allocated(): void
    {
        try {
            \Illuminate\Support\Facades\DB::transaction(function () {
                $this->expense();
                throw new \RuntimeException('Simulasi transaksi gagal');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulasi transaksi gagal', $error->getMessage());
        }
        $data = $this->fields();
        $this->post(route('expenses.store'), $data)->assertRedirect();
        $this->post(route('expenses.store'), $data)->assertRedirect();
        $this->assertSame('BEB/2026/11/000001', Expense::first()->number);
        $this->assertSame(1, Expense::count());
        $this->assertDatabaseHas('expense_number_sequences', ['period' => '2026/11', 'last_number' => 1]);
    }

    public function test_existing_expenses_are_numbered_by_creation_month_without_changing_business_data(): void
    {
        $migration = require database_path('migrations/2026_10_21_000002_add_monthly_expense_numbers.php');
        $migration->down();
        foreach (['2026-10-31 23:59:00', '2026-11-01 00:01:00', '2026-11-02 12:00:00'] as $index => $createdAt) {
            $fields = $this->fields();
            unset($fields['payment_status']);
            \Illuminate\Support\Facades\DB::table('expenses')->insert($fields + [
                'created_at' => $createdAt, 'updated_at' => $createdAt, 'created_by' => $this->owner->id,
                'status' => $index === 2 ? 'void' : 'confirmed',
            ]);
        }
        $before = \Illuminate\Support\Facades\DB::table('expenses')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $migration->up();
        $this->assertSame(['BEB/2026/10/000001', 'BEB/2026/11/000001', 'BEB/2026/11/000002'], Expense::orderBy('id')->get()->map->number->all());
        $after = \Illuminate\Support\Facades\DB::table('expenses')->orderBy('id')->get()->map(function ($row) {
            $data = (array) $row;
            unset($data['expense_number']);
            return $data;
        })->all();
        $this->assertEquals($before, $after);
        $this->assertSame('BEB/2026/11/000003', $this->expense()->number);
    }

    public function test_owner_pages_render_forms_details_and_empty_report(): void
    {
        $expense = $this->expense();
        $pages = [
            'expenses-list' => route('expenses.index'), 'expenses-create' => route('expenses.create'),
            'expenses-quarterly' => route('expenses.index', ['period_type' => 'quarterly', 'year' => 2026, 'quarter' => 4]),
            'expenses-yearly' => route('expenses.index', ['period_type' => 'yearly', 'year' => 2026]),
            'expenses-edit' => route('expenses.edit', $expense), 'expenses-show' => route('expenses.show', $expense),
            'profit-loss' => route('report.profit-loss'),
            'profit-loss-quarterly' => route('report.profit-loss', ['period_type' => 'quarterly', 'year' => 2026, 'quarter' => 4]),
            'profit-loss-yearly' => route('report.profit-loss', ['period_type' => 'yearly', 'year' => 2026]),
        ];
        foreach ($pages as $name => $url) {
            $response = $this->get($url)->assertOk()->assertSee('Pencatatan Beban')->assertSee('Laporan Laba Rugi');
            if (getenv('CAPTURE_EXPENSE_UI') === '1') {
                $directory = storage_path('framework/testing/expenses');
                if (!is_dir($directory)) mkdir($directory, 0777, true);
                file_put_contents($directory.'/'.$name.'.html', $response->getContent());
            }
        }
    }
}
