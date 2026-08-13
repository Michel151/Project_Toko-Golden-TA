<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_units', function (Blueprint $table) {
            $table->decimal('contains_quantity', 15, 4)->default(1)->after('unit_id')
                ->comment('1 satuan ini berisi berapa satuan turunan');
            $table->foreignId('contains_unit_id')->nullable()->after('contains_quantity')
                ->constrained('product_units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contains_unit_id');
            $table->dropColumn('contains_quantity');
        });
    }
};
