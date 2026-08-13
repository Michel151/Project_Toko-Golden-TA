<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('sale_number')->unique()->comment('Format: INV-YYYY-XXXXXX');
            $table->string('customer_name')->nullable();
            $table->date('sale_date');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->enum('payment_method', ['tunai', 'transfer'])->default('tunai');
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('change_amount', 15, 2)->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->enum('sale_type', ['grosir', 'ecer']);
            $table->decimal('quantity', 15, 4)->comment('Dalam satuan sesuai sale_type');
            $table->decimal('unit_price', 15, 2)->comment('Harga per satuan sesuai sale_type');
            $table->decimal('subtotal', 15, 2);
            $table->timestamps();
        });

        Schema::create('sale_item_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_item_id')->constrained('sale_items')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete()
                ->comment('Lokasi sumber pengambilan');
            $table->decimal('quantity', 15, 4)->comment('Jumlah diambil dari lokasi ini');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_sources');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
