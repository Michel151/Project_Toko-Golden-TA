<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('inventory_cost_settings', function (Blueprint $table) {
            $table->boolean('automatic_from_zero')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_cost_settings', fn (Blueprint $table) => $table->dropColumn('automatic_from_zero'));
    }
};
