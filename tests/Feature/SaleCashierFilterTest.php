<?php

namespace Tests\Feature;

use App\Models\{Sale, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleCashierFilterTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name, string $role): User
    {
        return User::create(['name' => $name, 'email' => $name.'@example.com', 'password' => 'password', 'role' => $role]);
    }

    private function sale(User $user, int $amount, string $payment = 'tunai', string $status = 'paid'): Sale
    {
        return Sale::create([
            'sale_number' => 'FILTER-'.(Sale::count() + 1), 'sale_date' => '2026-10-31',
            'total_amount' => $amount, 'payment_method' => $payment, 'status' => $status, 'created_by' => $user->id,
        ]);
    }

    public function test_owner_can_filter_cashier_with_other_filters_totals_and_pagination(): void
    {
        $owner = $this->user('Owner', 'owner');
        $first = $this->user('KasirA', 'kasir');
        $second = $this->user('KasirB', 'kasir');
        for ($i = 0; $i < 22; $i++) $this->sale($first, 1000);
        $this->sale($first, 400, 'transfer');
        $this->sale($first, 999, status: 'pending');
        $this->sale($second, 2000);
        $this->sale($owner, 3000);
        $this->actingAs($owner);
        $filters = ['cashier_id' => $first->id, 'payment_method' => 'tunai', 'status' => 'paid', 'from' => '2026-10-31', 'to' => '2026-10-31'];
        $page = $this->get(route('sale.sales.index', $filters))->assertOk()
            ->assertSee('id="saleCashier"', false)->assertSee('Semua Kasir')
            ->assertViewHas('totalAmount', 22000)
            ->assertViewHas('cashiers', fn ($users) => $users->pluck('id')->contains($owner->id))
            ->assertViewHas('sales', fn ($sales) => $sales->total() === 22 && $sales->count() === 20
                && $sales->every(fn ($sale) => $sale->created_by === $first->id));
        parse_str(parse_url($page->viewData('sales')->url(2), PHP_URL_QUERY), $query);
        foreach ($filters as $key => $value) $this->assertEquals($value, $query[$key]);
        $this->get(route('sale.sales.index', $filters + ['page' => 2]))->assertOk()
            ->assertViewHas('sales', fn ($sales) => $sales->count() === 2)->assertViewHas('totalAmount', 22000);
        $this->get(route('sale.sales.index', ['from' => '2026-10-31', 'to' => '2026-10-31']))->assertOk()
            ->assertViewHas('sales', fn ($sales) => $sales->total() === 26)->assertViewHas('totalAmount', 27400);
        $this->get(route('sale.sales.index', ['cashier_id' => $owner->id, 'from' => '2026-10-31', 'to' => '2026-10-31']))->assertOk()
            ->assertViewHas('sales', fn ($sales) => $sales->total() === 1)->assertViewHas('totalAmount', 3000);
    }

    public function test_cashier_cannot_use_filter_to_see_other_users_transactions(): void
    {
        $first = $this->user('KasirA', 'kasir');
        $second = $this->user('KasirB', 'kasir');
        $this->sale($first, 1000);
        $this->sale($second, 2000);
        $this->actingAs($second)->get(route('sale.sales.index', [
            'cashier_id' => $first->id, 'from' => '2026-10-31', 'to' => '2026-10-31',
        ]))->assertOk()->assertDontSee('id="saleCashier"', false)
            ->assertViewHas('cashiers', fn ($users) => $users->isEmpty())
            ->assertViewHas('sales', fn ($sales) => $sales->total() === 1 && $sales->first()->created_by === $second->id)
            ->assertViewHas('totalAmount', 2000);
    }

    public function test_owner_filter_rejects_invalid_user_ids(): void
    {
        $this->actingAs($this->user('Owner', 'owner'));
        foreach (['invalid', 999999] as $id) {
            $this->getJson(route('sale.sales.index', ['cashier_id' => $id]))
                ->assertUnprocessable()->assertJsonValidationErrors('cashier_id');
        }
    }
}
