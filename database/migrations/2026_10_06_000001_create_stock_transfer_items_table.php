<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 4);
            $table->timestamps();
            $table->unique(['stock_transfer_id', 'product_id']);
        });

        DB::table('stock_transfers')->orderBy('id')->chunkById(250, function ($transfers) {
            DB::table('stock_transfer_items')->insert($transfers->map(fn ($transfer) => [
                'stock_transfer_id' => $transfer->id,
                'product_id' => $transfer->product_id,
                'product_unit_id' => $transfer->product_unit_id,
                'quantity' => $transfer->quantity,
                'created_at' => $transfer->created_at,
                'updated_at' => $transfer->updated_at,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
    }
};
