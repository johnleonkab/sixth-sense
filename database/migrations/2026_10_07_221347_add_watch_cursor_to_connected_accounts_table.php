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
        Schema::table('connected_accounts', function (Blueprint $table) {
            // Where the last check for new mail stopped (Gmail historyId).
            $table->string('watch_cursor')->nullable()->after('last_refreshed_at');
            $table->timestamp('watched_at')->nullable()->after('watch_cursor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('connected_accounts', function (Blueprint $table) {
            $table->dropColumn(['watch_cursor', 'watched_at']);
        });
    }
};
