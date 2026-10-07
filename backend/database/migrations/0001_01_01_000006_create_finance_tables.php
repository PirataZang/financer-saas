<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            // Emoji: renders the same on the web and in Telegram buttons.
            $table->string('icon', 16)->nullable();
            $table->string('color', 7)->default('#64748b');
            // Values frozen here on purpose (migrations must not change with App\Enums\CategoryType).
            $table->enum('type', ['income', 'expense', 'both'])->default('both')->index();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('finance_transactions', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['income', 'expense'])->index();
            $table->decimal('amount', 15, 2);
            $table->string('description');
            $table->foreignId('category_id')->constrained('finance_categories')->restrictOnDelete();
            // Plain Y-m-d (no time/timezone): the day the money moved.
            $table->date('date')->index();
            $table->enum('status', ['paid', 'pending'])->default('paid');
            // Where the entry came from; the core never depends on it.
            $table->enum('source', ['manual', 'telegram', 'import', 'open_finance'])->default('manual')->index();
            // Id at the source (Telegram message, bank transaction id): makes every entry point idempotent.
            $table->string('external_id', 120)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['source', 'external_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE finance_transactions ADD CONSTRAINT finance_transactions_amount_positive CHECK (amount > 0)');
        }

        // Telegram users that unlocked the bot. The bot itself authenticates with BOT_API_TOKEN;
        // this table says which Telegram users may act through it.
        Schema::create('telegram_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_user_id')->unique();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->timestamp('activated_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_accounts');
        Schema::dropIfExists('finance_transactions');
        Schema::dropIfExists('finance_categories');
    }
};
