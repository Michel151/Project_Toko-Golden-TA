<?php

namespace Tests\Feature;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SaleTransactionTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_time_is_backfilled_from_creation_without_changing_note_date(): void
    {
        config(['app.timezone'=>'UTC']);
        $user=User::create(['name'=>'Owner','email'=>'time@example.com','password'=>'password','role'=>'owner']);
        $sale=Sale::create(['sale_number'=>'INV-TIME','sale_date'=>'2026-10-10','status'=>'paid','total_amount'=>1000,'payment_method'=>'tunai','created_by'=>$user->id]);
        DB::table('sales')->where('id',$sale->id)->update(['created_at'=>'2026-10-14 03:04:05','updated_at'=>'2026-10-14 03:04:05']);
        Schema::table('sales',fn(Blueprint $table)=>$table->dropColumn('sale_time'));
        $migration=require database_path('migrations/2026_10_14_000002_add_sale_time_to_sales_table.php');
        $migration->up();
        $sale->refresh();
        $this->assertSame('10:04:05',$sale->sale_time);
        $this->assertSame('2026-10-10',$sale->sale_date->toDateString());
        $this->assertSame('2026-10-14 03:04:05',$sale->getRawOriginal('updated_at'));
        $this->actingAs($user)->get(route('sale.sales.index'))->assertOk()->assertSee('10:04:05 WIB');
        $this->get(route('sale.sales.show',$sale))->assertOk()->assertSee('10:04:05 WIB');
        $this->get(route('sale.sales.print',['sale'=>$sale,'popup'=>1]))->assertOk()->assertSee('10:04:05 WIB');
    }

    public function test_unknown_time_is_not_fabricated(): void
    {
        $sale=new Sale(['sale_date'=>'2026-10-10']);
        $this->assertSame('Jam tidak tercatat',$sale->transaction_time_label);
    }
}
