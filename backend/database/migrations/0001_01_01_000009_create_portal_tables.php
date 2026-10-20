<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portal do cliente: projects with steps and announcements (managed in the CRM, read-only for the
 * customer), bills = income transactions linked to a customer, and the customer's portal session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            // Values frozen here on purpose (migrations must not change with App\Enums\ProjectStatus).
            $table->enum('status', ['planning', 'in_progress', 'paused', 'done', 'cancelled'])->default('planning')->index();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('project_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->enum('status', ['pending', 'in_progress', 'done'])->default('pending');
            $table->date('due_date')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // No project = for every customer of the company; with a project = only its customer.
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title', 150);
            $table->text('body');
            $table->timestamps();
        });

        // An income linked to a customer shows in their portal (pending = bill to pay).
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->index()->constrained()->nullOnDelete();
        });

        // Portal session, like users.token: only the sha256 of the bearer token is stored.
        Schema::table('customers', function (Blueprint $table) {
            $table->string('portal_token', 64)->nullable()->unique();
            $table->timestamp('portal_expire_at')->nullable();
        });

        // Public, non-secret address of the company's portal (/portal/<slug>); filled on first use.
        Schema::table('companies', function (Blueprint $table) {
            $table->string('portal_slug', 80)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('portal_slug'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn(['portal_token', 'portal_expire_at']));
        Schema::table('finance_transactions', fn (Blueprint $table) => $table->dropConstrainedForeignId('customer_id'));
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('project_steps');
        Schema::dropIfExists('projects');
    }
};
