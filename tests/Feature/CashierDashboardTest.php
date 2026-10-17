<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\SaleDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashierDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrics_show_only_cashiers_operational_work_and_paid_sales(): void
    {
        $this->travelTo(now('Asia/Jakarta')->setTime(17, 15));
        $user = User::create(['name'=>'Kasir', 'email'=>'cashier@example.com', 'password'=>'password', 'role'=>'kasir']);
        $other = User::create(['name'=>'Lain', 'email'=>'other@example.com', 'password'=>'password', 'role'=>'kasir']);
        $make = fn ($owner, $status, $amount, $method='tunai', $hold=null, $date=null) => Sale::create([
            'sale_number'=>'INV-'.Str::uuid(), 'customer_name'=>$owner->id === $user->id ? 'Pelanggan Saya' : 'Pelanggan Rahasia',
            'created_by'=>$owner->id, 'sale_date'=>$date ?? today(), 'status'=>$status, 'total_amount'=>$amount,
            'payment_method'=>$method, 'amount_paid'=>$status==='paid' ? $amount+5000 : 0, 'change_amount'=>$status==='paid' ? 5000 : 0, 'hold_until'=>$hold,
        ]);
        $make($user,'paid',10000);
        $make($user,'paid',20000,'transfer');
        $make($user,'paid',50000,'tunai',null,today()->subDay());
        $soon=$make($user,'pending',10000,'tunai',now()->addMinutes(30));
        $make($user,'pending',20000,'tunai',now()->addHours(2));
        $make($user,'pending',70000,'tunai',now()->subMinute());
        $make($user,'cancelled',30000);
        $make($user,'cancelled_auto',1000);
        $make($other,'paid',999999);
        $make($other,'pending',999999,'tunai',now()->addMinutes(15));
        foreach ([[$user,'active',['items'=>[['product_id'=>123]],'customer_name'=>'Draft Saya']],[$other,'active',['items'=>[['product_id'=>123]],'customer_name'=>'Draft Rahasia']],[$user,'discarded',null],[$user,'active',['items'=>[],'customer_name'=>'','amount_paid'=>0]]] as [$owner,$status,$payload]) {
            SaleDraft::create(['id'=>Str::uuid(),'user_id'=>$owner->id,'status'=>$status,'payload'=>$payload]);
        }
        $response=$this->actingAs($user)->get(route('dashboard'));
        $response->assertOk()->assertViewIs('dashboard.cashier')
            ->assertViewHas('cashierSalesToday',30000)->assertViewHas('cashierTransactionsToday',2)
            ->assertViewHas('cashierCashToday',10000)->assertViewHas('cashierTransferToday',20000)
            ->assertViewHas('cashierPendingCount',2)->assertViewHas('cashierPendingAmount',30000)
            ->assertViewHas('cashierDueSoonCount',1)->assertViewHas('cashierExpiredPendingCount',1)
            ->assertViewHas('cashierCancelledToday',2)->assertViewHas('cashierDraftCount',1)
            ->assertViewHas('cashierStatusCounts',['paid'=>2,'pending'=>3,'cancelled'=>2])
            ->assertViewHas('cashierPendingSales',fn($sales)=>$sales->first()->id===$soon->id)
            ->assertDontSee('Penjualan Bulan Ini')->assertDontSee('Tren Penjualan Saya')->assertDontSee('chart.umd')
            ->assertDontSee('Pelanggan Rahasia')->assertDontSee('Draft Rahasia')->assertSee('Draft Saya');
    }

    public function test_empty_day_shows_neutral_chart_without_fabricated_percentages(): void
    {
        $user=User::create(['name'=>'Kasir', 'email'=>'empty@example.com', 'password'=>'password', 'role'=>'kasir']);
        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertViewHas('cashierStatusCounts',['paid'=>0,'pending'=>0,'cancelled'=>0])
            ->assertSee('background:#e8edeb;',false)->assertSee('Belum ada nota hari ini.')->assertDontSee('100,0%');
    }
}
