<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->changeTable(function (Blueprint $table) {
            $table->enum('status', ['draft', 'pending', 'partial', 'completed', 'cancelled', 'closed'])->default('pending')->change();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->text('closure_reason')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('purchase_orders')->whereIn('status', ['draft', 'cancelled', 'closed'])->exists()) {
            throw new RuntimeException('Rollback ditolak: masih ada PO draf, dibatalkan, atau ditutup. Riwayat PO harus dipertahankan.');
        }
        $this->changeTable(function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['confirmed_at', 'cancelled_at', 'cancellation_reason', 'closed_at', 'closure_reason']);
            $table->enum('status', ['pending', 'partial', 'completed'])->default('pending')->change();
        });
    }

    private function changeTable(Closure $change): void
    {
        // SQLite rebuilds the table when changing an enum/check constraint.
        // MySQL changes the enum in place and keeps all existing status values.
        $apply = fn () => Schema::table('purchase_orders', $change);
        if (DB::getDriverName() === 'sqlite') Schema::withoutForeignKeyConstraints($apply);
        else $apply();
    }
};
