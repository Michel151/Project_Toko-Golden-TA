<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_expirable')->default(false)->after('barcode');
        });

        Schema::table('purchase_receipt_items', function (Blueprint $table) {
            $table->date('expired_date')->nullable()->after('quantity_received');
        });

        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('purchase_receipt_item_id')->nullable()->constrained('purchase_receipt_items')->nullOnDelete();
            $table->date('received_date');
            $table->date('expired_date')->nullable();
            $table->decimal('quantity_initial', 15, 4);
            $table->decimal('quantity_remaining', 15, 4);
            $table->timestamps();

            $table->index(['product_id', 'location_id', 'expired_date']);
        });

        Schema::table('sale_item_sources', function (Blueprint $table) {
            $table->foreignId('stock_batch_id')->nullable()->after('location_id')->constrained('stock_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_item_sources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_batch_id');
        });

        Schema::dropIfExists('stock_batches');

        Schema::table('purchase_receipt_items', function (Blueprint $table) {
            $table->dropColumn('expired_date');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_expirable');
        });
    }
};
