<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Importação de dados por planilha. `imports` é a execução; `import_rows` guarda cada linha com o
 * resultado (pending → created | updated | failed + motivo), então andamento e erros saem dela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30)->default('customer');
            $table->string('filename');
            $table->unsignedInteger('total');
            $table->timestamps();

            $table->index(['company_id', 'type']);
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line');
            $table->json('data');
            // Frozen here on purpose: pending, created, updated, failed.
            $table->string('status', 10)->default('pending');
            $table->text('error')->nullable();

            $table->index(['import_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('imports');
    }
};
