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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 11)->nullable();
            // Values frozen here on purpose (migrations must not change with App\Enums\CustomerStatus).
            $table->enum('status', ['lead', 'warming_up', 'customer', 'cancelled'])->default('lead')->index();
            $table->boolean('active')->default(true);
            $table->string('zip_code', 8)->nullable();
            $table->string('street')->nullable();
            // String: "123A", "s/n", "km 12".
            $table->string('number', 20)->nullable();
            $table->string('complement')->nullable();
            $table->string('neighborhood')->nullable();
            $table->string('reference')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // "email OR phone" enforced by the database too. SQLite (test suite) can't add a
        // CHECK to an existing table; there the Customer model's saving guard covers it.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE customers ADD CONSTRAINT customers_email_or_phone_check
                CHECK (COALESCE(email, '') <> '' OR COALESCE(phone, '') <> '')
            SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
