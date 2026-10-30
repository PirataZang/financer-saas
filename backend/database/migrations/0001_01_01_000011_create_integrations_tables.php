<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SOURCES = ['manual', 'telegram', 'import', 'open_finance'];

    public function up(): void
    {
        // One row per company and provider (Configurações › Integrações). Read-only by design:
        // credentials are an OAuth grant used only for GETs (App\Service\MercadoPagoClient).
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('provider', 40);
            $table->boolean('active')->default(true);
            // Encrypted JSON (access/refresh token, expiry). Never serialized (#[ProtectedColumns]).
            $table->text('credentials')->nullable();
            // Who was connected (provider user id, nickname, e-mail, granted scope): shown on the card.
            $table->json('account')->nullable();
            $table->json('settings')->nullable();
            // Sync resumes from the last change seen at the provider.
            $table->timestamp('sync_cursor')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'provider']);
        });

        // Mirror of the account's Mercado Pago payments: what the dashboard's integration charts read.
        // Transactions (finance core) are derived from it; removing the integration removes both.
        Schema::create('mercado_pago_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('integration_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('mp_id');
            // in = the account received it (sale, Pix received); out = the account paid it.
            $table->string('direction', 3);
            $table->string('status', 30);
            $table->string('status_detail', 80)->nullable();
            $table->string('payment_type', 30)->nullable();
            $table->string('payment_method', 40)->nullable();
            $table->string('description')->nullable();
            $table->decimal('amount', 15, 2);
            $table->decimal('fee', 15, 2)->default(0);
            $table->decimal('net', 15, 2)->nullable();
            $table->decimal('refunded', 15, 2)->default(0);
            $table->unsignedSmallInteger('installments')->default(1);
            // Local day (company timezone) the payment was approved, or created when not approved.
            $table->date('date');
            $table->timestamp('mp_updated_at');
            $table->timestamps();

            $table->unique(['company_id', 'mp_id']);
            $table->index(['company_id', 'date']);
        });

        // Categories an integration created and keeps (e.g. "Tarifas · Mercado Pago"): the key finds
        // them again after a rename; the screens show them as the integration's and block deleting them.
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->string('integration_key', 60)->nullable();
            $table->unique(['company_id', 'integration_key']);
        });

        $this->setSources([...self::SOURCES, 'mercado_pago']);
    }

    public function down(): void
    {
        DB::table('finance_transactions')->where('source', 'mercado_pago')->delete();
        $this->setSources(self::SOURCES);

        Schema::table('finance_categories', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'integration_key']);
            $table->dropColumn('integration_key');
        });

        Schema::dropIfExists('mercado_pago_payments');
        Schema::dropIfExists('integrations');
    }

    /** finance_transactions.source is an enum (CHECK constraint): values frozen per migration. */
    private function setSources(array $sources): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $list = implode(', ', array_map(fn ($s) => "'{$s}'", $sources));
            DB::statement('ALTER TABLE finance_transactions DROP CONSTRAINT IF EXISTS finance_transactions_source_check');
            DB::statement("ALTER TABLE finance_transactions ADD CONSTRAINT finance_transactions_source_check CHECK (source IN ({$list}))");

            return;
        }

        Schema::table('finance_transactions', fn (Blueprint $table) => $table->enum('source', $sources)->default('manual')->change());
    }
};
