<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_supplier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('buy_price', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['supplier_id', 'product_id']);
        });

        $now = now();
        $suppliers = DB::table('suppliers')->pluck('id');
        $products = DB::table('products')->select('id', 'buy_price')->get();
        $rows = [];

        foreach ($suppliers as $supplierId) {
            foreach ($products as $product) {
                $rows[] = [
                    'supplier_id' => $supplierId,
                    'product_id' => $product->id,
                    'buy_price' => $product->buy_price,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('product_supplier')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_supplier');
    }
};
