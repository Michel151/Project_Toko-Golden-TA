<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->date('recognized_date')->index();
            $table->string('category', 40);
            $table->string('description', 500);
            $table->decimal('amount', 14, 2);
            $table->string('status', 20)->default('draft')->index();
            $table->date('paid_date')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->string('proof_path')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('submission_token')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
