<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->foreignId('buy_unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('buy_price', 15, 2)->default(0);
            $table->decimal('sell_price_wholesale', 15, 2)->default(0);
            $table->boolean('can_retail')->default(false);
            $table->foreignId('retail_unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->decimal('conversion_factor', 10, 4)->nullable()->comment('1 satuan beli = X satuan ecer');
            $table->decimal('sell_price_retail', 15, 2)->nullable();
            $table->decimal('min_stock_total', 15, 4)->default(0)->comment('Stok minimum total semua lokasi');
            $table->timestamps();
        });

        Schema::create('product_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->decimal('min_stock', 15, 4)->default(0)->comment('Stok minimum per lokasi');
            $table->timestamps();
            $table->unique(['product_id', 'location_id']);
        });

        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->decimal('quantity', 15, 4)->default(0)
                ->comment('Dalam satuan ecer jika can_retail=true, satuan beli jika tidak');
            $table->timestamps();
            $table->unique(['product_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
        Schema::dropIfExists('product_locations');
        Schema::dropIfExists('products');
    }
};
