<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('monthly_taxes', function (Blueprint $table) {
            $table->id();
            $table->string('month', 7)->unique();
            $table->decimal('amount', 14, 2);
            $table->string('description', 500);
            $table->string('status', 20)->default('draft');
            $table->string('proof_path')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('monthly_tax_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_tax_id')->constrained()->restrictOnDelete();
            $table->json('snapshot');
            $table->string('action', 20);
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_tax_revisions');
        Schema::dropIfExists('monthly_taxes');
    }
};
