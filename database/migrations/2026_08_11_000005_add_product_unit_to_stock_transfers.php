<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->foreignId('product_unit_id')->nullable()->after('product_id')
                ->constrained('product_units')->nullOnDelete();
        });

        DB::table('stock_transfers')
            ->select('id', 'product_id')
            ->orderBy('id')
            ->chunkById(250, function ($transfers) {
                $productIds = $transfers->pluck('product_id')->unique();
                $units = DB::table('product_units')
                    ->whereIn('product_id', $productIds)
                    ->where('is_base_unit', true)
                    ->pluck('id', 'product_id');

                foreach ($transfers as $transfer) {
                    $productUnitId = $units->get($transfer->product_id);
                    if ($productUnitId) {
                        DB::table('stock_transfers')
                            ->where('id', $transfer->id)
                            ->update(['product_unit_id' => $productUnitId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_unit_id');
        });
    }
};
