<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Global bypass: master users pass every permission check.
            $table->boolean('master')->default(false);
        });

        // Mirror of config/permissions.php, kept in sync by `php artisan permission sync`.
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('module')->index();
            $table->string('module_name');
            $table->string('action');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['module', 'action']);
        });

        // Linked by id so renaming a permission key keeps every assignment.
        Schema::create('permission_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();

            $table->primary(['user_id', 'permission_id']);
            $table->index('permission_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permission_user');
        Schema::dropIfExists('permissions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('master');
        });
    }
};
