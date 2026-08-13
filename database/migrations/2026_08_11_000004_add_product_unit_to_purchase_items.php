<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignId('product_unit_id')->nullable()->after('product_id')
                ->constrained('product_units')->nullOnDelete();
        });

        Schema::table('purchase_receipt_items', function (Blueprint $table) {
            $table->foreignId('product_unit_id')->nullable()->after('product_id')
                ->constrained('product_units')->nullOnDelete();
        });

        DB::table('purchase_order_items')
            ->join('products', 'purchase_order_items.product_id', '=', 'products.id')
            ->leftJoin('product_units', function ($join) {
                $join->on('product_units.product_id', '=', 'products.id')
                    ->where('product_units.is_default_purchase', true);
            })
            ->select('purchase_order_items.id', 'product_units.id as product_unit_id')
            ->orderBy('purchase_order_items.id')
            ->chunk(250, function ($items) {
                foreach ($items as $item) {
                    if ($item->product_unit_id) {
                        DB::table('purchase_order_items')
                            ->where('id', $item->id)
                            ->update(['product_unit_id' => $item->product_unit_id]);
                    }
                }
            });

        DB::table('purchase_receipt_items')
            ->select('id', 'purchase_order_item_id')
            ->orderBy('id')
            ->chunkById(250, function ($receiptItems) {
                $poItemIds = $receiptItems->pluck('purchase_order_item_id')->filter()->unique();
                $poUnits = DB::table('purchase_order_items')
                    ->whereIn('id', $poItemIds)
                    ->pluck('product_unit_id', 'id');

                foreach ($receiptItems as $receiptItem) {
                    $productUnitId = $poUnits->get($receiptItem->purchase_order_item_id);
                    if ($productUnitId) {
                        DB::table('purchase_receipt_items')
                            ->where('id', $receiptItem->id)
                            ->update(['product_unit_id' => $productUnitId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('purchase_receipt_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_unit_id');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_unit_id');
        });
    }
};
