<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_cost_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->timestamp('enabled_at')->nullable();
            $table->foreignId('enabled_by')->nullable()->constrained('users')->restrictOnDelete();
        });
        DB::table('inventory_cost_settings')->insert(['id' => 1]);
        Schema::create('inventory_cost_balances', function (Blueprint $table) {
            $table->foreignId('product_id')->primary()->constrained()->restrictOnDelete();
            $table->decimal('quantity', 20, 4)->default(0);
            $table->decimal('inventory_value', 24, 2)->default(0);
            $table->decimal('average_cost', 24, 10)->default(0);
            $table->date('last_event_date')->nullable();
            $table->unsignedBigInteger('baseline_movement_id')->default(0);
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });
        Schema::create('inventory_cost_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('event_key', 150)->unique();
            $table->string('kind', 30);
            $table->date('event_date')->index();
            $table->decimal('quantity_change', 20, 4);
            $table->decimal('value_change', 24, 2);
            $table->decimal('quantity_after', 20, 4);
            $table->decimal('value_after', 24, 2);
            $table->decimal('average_after', 24, 10);
            $table->nullableMorphs('reference');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
        });
        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('cost_method', 25)->default('legacy_master');
            $table->decimal('cost_base_quantity', 20, 4)->nullable();
            $table->decimal('cost_per_base_unit', 24, 10)->nullable();
        });
        Schema::table('purchase_receipt_items', function (Blueprint $table) {
            $table->decimal('cost_base_quantity', 20, 4)->nullable();
            $table->decimal('acquisition_value', 24, 2)->nullable();
        });
        Schema::table('stock_opname_items', function (Blueprint $table) {
            $table->decimal('valuation_unit_cost', 24, 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stock_opname_items', fn (Blueprint $table) => $table->dropColumn('valuation_unit_cost'));
        Schema::table('purchase_receipt_items', fn (Blueprint $table) => $table->dropColumn(['cost_base_quantity', 'acquisition_value']));
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropColumn(['cost_method', 'cost_base_quantity', 'cost_per_base_unit']));
        Schema::dropIfExists('inventory_cost_entries');
        Schema::dropIfExists('inventory_cost_balances');
        Schema::dropIfExists('inventory_cost_settings');
    }
};
