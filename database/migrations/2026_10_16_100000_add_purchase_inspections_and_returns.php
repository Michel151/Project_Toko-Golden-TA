<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_receipts', fn (Blueprint $table) => $table->uuid('submission_token')->nullable()->unique());
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('root_purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
        });
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('quantity_reserved', 15, 4)->default(0);
            $table->foreignId('source_purchase_order_item_id')->nullable()->constrained('purchase_order_items')->restrictOnDelete();
        });
        Schema::create('purchase_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_good', 15, 4)->default(0);
            $table->decimal('quantity_damaged', 15, 4)->default(0);
            $table->decimal('quantity_wrong', 15, 4)->default(0);
            $table->decimal('quantity_missing', 15, 4)->default(0);
            $table->foreignId('quarantine_location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['purchase_receipt_id', 'purchase_order_item_id'], 'inspection_receipt_item_unique');
        });
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_number')->unique();
            $table->uuid('submission_token')->nullable()->unique();
            $table->foreignId('purchase_receipt_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('replacement_purchase_order_id')->nullable()->unique()->constrained('purchase_orders')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_inspection_id')->constrained()->restrictOnDelete();
            $table->string('kind');
            $table->decimal('quantity', 15, 4);
            $table->timestamps();
            $table->unique(['purchase_return_id', 'purchase_inspection_id', 'kind'], 'return_inspection_kind_unique');
        });
        foreach (['view', 'create', 'dispatch'] as $action) {
            DB::table('role_permissions')->insertOrIgnore([
                'role' => 'staf_gudang', 'permission' => 'purchase.returns.'.$action,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', 'like', 'purchase.returns.%')->delete();
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('purchase_inspections');
        Schema::table('purchase_receipts', function (Blueprint $table) {
            $table->dropUnique(['submission_token']);
            $table->dropColumn('submission_token');
        });
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_purchase_order_item_id');
            $table->dropColumn('quantity_reserved');
        });
        Schema::table('purchase_orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('root_purchase_order_id'));
    }
};
