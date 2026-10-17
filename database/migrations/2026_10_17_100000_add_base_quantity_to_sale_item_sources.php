<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_item_sources', function (Blueprint $table) {
            $table->decimal('base_quantity', 15, 4)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('sale_item_sources', fn (Blueprint $table) => $table->dropColumn('base_quantity'));
    }
};
