<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_opname_items', fn (Blueprint $table) => $table->boolean('tracks_expiry')->nullable());
        Schema::create('stock_opname_expiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_opname_item_id')->constrained()->cascadeOnDelete();
            $table->date('expired_date')->nullable();
            $table->decimal('system_quantity',15,4)->default(0);
            $table->decimal('physical_quantity',15,4)->default(0);
            $table->json('batch_snapshot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_expiries');
        Schema::table('stock_opname_items', fn (Blueprint $table) => $table->dropColumn('tracks_expiry'));
    }
};
