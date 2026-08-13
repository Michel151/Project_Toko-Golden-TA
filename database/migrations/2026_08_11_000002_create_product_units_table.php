<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('conversion_to_base', 15, 4)->default(1)->comment('1 satuan ini = X satuan dasar produk');
            $table->decimal('buy_price', 15, 2)->default(0);
            $table->decimal('sell_price', 15, 2)->default(0);
            $table->boolean('is_base_unit')->default(false);
            $table->boolean('is_default_purchase')->default(false);
            $table->boolean('is_default_sale')->default(false);
            $table->timestamps();

            $table->unique(['product_id', 'unit_id']);
            $table->index(['product_id', 'is_base_unit']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('product_unit_id')->nullable()->after('product_id')
                ->constrained('product_units')->nullOnDelete();
        });

        DB::table('products')->orderBy('id')->chunkById(250, function ($products) {
            foreach ($products as $product) {
                if ($product->can_retail && $product->retail_unit_id && (float) $product->conversion_factor > 0) {
                    DB::table('product_units')->updateOrInsert(
                        ['product_id' => $product->id, 'unit_id' => $product->retail_unit_id],
                        [
                            'conversion_to_base' => 1,
                            'buy_price' => (float) $product->buy_price / (float) $product->conversion_factor,
                            'sell_price' => (float) ($product->sell_price_retail ?? 0),
                            'is_base_unit' => true,
                            'is_default_purchase' => false,
                            'is_default_sale' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );

                    DB::table('product_units')->updateOrInsert(
                        ['product_id' => $product->id, 'unit_id' => $product->buy_unit_id],
                        [
                            'conversion_to_base' => (float) $product->conversion_factor,
                            'buy_price' => (float) $product->buy_price,
                            'sell_price' => (float) $product->sell_price_wholesale,
                            'is_base_unit' => false,
                            'is_default_purchase' => true,
                            'is_default_sale' => false,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                } else {
                    DB::table('product_units')->updateOrInsert(
                        ['product_id' => $product->id, 'unit_id' => $product->buy_unit_id],
                        [
                            'conversion_to_base' => 1,
                            'buy_price' => (float) $product->buy_price,
                            'sell_price' => (float) $product->sell_price_wholesale,
                            'is_base_unit' => true,
                            'is_default_purchase' => true,
                            'is_default_sale' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_unit_id');
        });

        Schema::dropIfExists('product_units');
    }
};
