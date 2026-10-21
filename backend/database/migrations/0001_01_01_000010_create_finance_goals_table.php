<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Metas: each goal owns one category (name/icon/color live there) and its transactions are the
 * goal's separate money — aportes/gastos are expenses in it, resgates/estornos are incomes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->unique()->constrained('finance_categories')->restrictOnDelete();
            // Values frozen here on purpose (migrations must not change with App\Enums\GoalType).
            $table->enum('type', ['saving', 'spending'])->index();
            $table->decimal('target_amount', 15, 2);
            // Only transactions dated in [start_date, end_date] count; no end_date = open-ended.
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE finance_goals ADD CONSTRAINT finance_goals_target_positive CHECK (target_amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_goals');
    }
};
