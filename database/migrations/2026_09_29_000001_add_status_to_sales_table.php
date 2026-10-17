<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('status', 30)->default('paid')->index();
            $table->dateTime('hold_until')->nullable()->index()->comment('Batas pembayaran dalam waktu lokal Asia/Jakarta');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['status', 'hold_until']);
        });
    }
};
