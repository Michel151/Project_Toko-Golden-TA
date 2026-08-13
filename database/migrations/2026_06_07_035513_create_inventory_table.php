<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_number')->unique()->comment('Format: TRF-YYYY-XXXXXX');
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('from_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('locations')->restrictOnDelete();
            $table->decimal('quantity', 15, 4)->comment('Dalam satuan ecer/beli sesuai produk');
            $table->date('transfer_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->id();
            $table->string('opname_number')->unique()->comment('Format: OPN-YYYY-XXXXXX');
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->date('opname_date');
            $table->enum('status', ['draft', 'completed'])->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_opname_id')->constrained('stock_opnames')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('system_quantity', 15, 4)->comment('Stok sistem saat opname');
            $table->decimal('physical_quantity', 15, 4)->comment('Stok fisik hasil hitung');
            $table->decimal('difference', 15, 4)->comment('Selisih: physical - system');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_items');
        Schema::dropIfExists('stock_opnames');
        Schema::dropIfExists('stock_transfers');
    }
};
