<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_import_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status',20)->default('pending');
            $table->longText('payload')->nullable();
            $table->json('result')->nullable();
            $table->dateTime('expires_at')->index();
            $table->timestamps();
        });
        Schema::table('stock_batches', fn (Blueprint $table) => $table->string('batch_code',100)->nullable());
    }

    public function down(): void
    {
        Schema::table('stock_batches', fn (Blueprint $table) => $table->dropColumn('batch_code'));
        Schema::dropIfExists('product_import_runs');
    }
};
