<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->time('sale_time')->nullable()->after('sale_date');
        });

        // Older notes already have their original save time in created_at.
        DB::table('sales')->whereNotNull('created_at')->orderBy('id')->chunkById(200, function ($sales) {
            foreach ($sales as $sale) {
                DB::table('sales')->where('id', $sale->id)->update([
                    'sale_time' => Carbon::parse($sale->created_at, config('app.timezone'))
                        ->timezone('Asia/Jakarta')->format('H:i:s'),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('sale_time'));
    }
};
