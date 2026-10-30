<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Templates de documento: HTML do editor com variáveis {{Cliente.name}}, preenchidas ao emitir o PDF. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('subject')->nullable();
            $table->string('description', 25)->nullable();
            $table->boolean('active')->default(true);
            // Sanitized HTML (DocumentService::sanitizeHtml); images pasted in the editor are base64.
            $table->longText('content')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
