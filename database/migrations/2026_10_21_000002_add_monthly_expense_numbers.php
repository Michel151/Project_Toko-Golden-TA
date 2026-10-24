<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('expense_number_sequences', function (Blueprint $table) {
            $table->string('period', 7)->primary();
            $table->unsignedInteger('last_number')->default(0);
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('expense_number', 40)->nullable()->unique();
        });

        DB::transaction(function () {
            DB::table('expenses')->orderBy('id')->chunkById(200, function ($expenses) {
                foreach ($expenses as $expense) {
                    $issuedAt = CarbonImmutable::parse($expense->created_at ?? $expense->recognized_date, config('app.timezone'))->setTimezone('Asia/Jakarta');
                    $period = $issuedAt->format('Y/m');
                    DB::table('expense_number_sequences')->insertOrIgnore(['period' => $period, 'last_number' => 0]);
                    DB::table('expense_number_sequences')->where('period', $period)->increment('last_number');
                    $sequence = (int) DB::table('expense_number_sequences')->where('period', $period)->value('last_number');
                    if ($sequence > 999999) throw new RuntimeException('Nomor beban bulanan melebihi enam digit.');
                    DB::table('expenses')->where('id', $expense->id)->update([
                        'expense_number' => 'BEB/'.$period.'/'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
                    ]);
                }
            });
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->string('expense_number', 40)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropUnique(['expense_number']);
            $table->dropColumn('expense_number');
        });
        Schema::dropIfExists('expense_number_sequences');
    }
};
