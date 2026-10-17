<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Multi-tenancy: every record belongs to a company (tenant). Company 1 is the
 * "Empresa Padrão": always exists, can't be deleted, and owns pre-existing data.
 */
return new class extends Migration
{
    /** Tables whose rows belong to a company. A new tenant table must be added here (TenancyTest enforces it). */
    private const TENANT_TABLES = ['users', 'customers', 'activity_log', 'finance_categories', 'finance_transactions', 'telegram_accounts'];

    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('trade_name')->nullable();
            // CPF (11) or CNPJ (14), digits only.
            $table->string('document', 14)->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('zip_code', 8)->nullable();
            $table->string('street')->nullable();
            $table->string('number', 20)->nullable();
            $table->string('neighborhood')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            // License: access is active only with a token and while expires_at (inclusive) hasn't passed.
            // No token = blocked. No expires_at = never expires.
            $table->string('token', 64)->nullable()->unique();
            $table->date('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // First row of a fresh table: id 1, and the sequence stays in step.
        DB::table('companies')->insert([
            'name' => 'Empresa Padrão',
            'trade_name' => 'Empresa Padrão',
            'description' => 'Empresa padrão do sistema. Não pode ser excluída.',
            'country' => 'Brasil',
            'token' => Str::random(40),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Nullable → backfill → NOT NULL, so this also upgrades a database that already has rows.
        foreach (self::TENANT_TABLES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('company_id')->nullable()->index()->constrained()->restrictOnDelete());
            DB::table($name)->update(['company_id' => 1]);
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('company_id')->nullable(false)->change());
        }

        // Idempotency keys are unique per company, like every other per-tenant value.
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropUnique(['source', 'external_id']);
            $table->unique(['company_id', 'source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'source', 'external_id']);
            $table->unique(['source', 'external_id']);
        });
        foreach (self::TENANT_TABLES as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('company_id'));
        }
        Schema::dropIfExists('companies');
    }
};
