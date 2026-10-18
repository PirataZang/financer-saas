<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurações: per-company settings (appearance, finance defaults), the company's Telegram
 * link code, and per-user preferences. Settings are JSON: SettingsService owns keys and defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('settings')->nullable();
            // Code a Telegram user sends (or opens as t.me link) to link to this company. Null = linking off.
            $table->string('telegram_code', 32)->nullable()->unique();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('preferences'));
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['telegram_code']);
            $table->dropColumn(['settings', 'telegram_code']);
        });
    }
};
