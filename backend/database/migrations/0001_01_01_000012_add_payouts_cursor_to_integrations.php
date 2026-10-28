<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // End of the last "Liberações" report read: sent Pix and transfers resume from there.
        Schema::table('integrations', function (Blueprint $table) {
            $table->timestamp('payouts_cursor')->nullable()->after('sync_cursor');
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn('payouts_cursor');
        });
    }
};
