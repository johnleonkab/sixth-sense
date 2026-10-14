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
            // Which calendars Wingman follows, which meetings it never listens to, whether transcripts are kept.
            $table->json('wingman_settings')->nullable()->after('wingman_enabled');
        });

        Schema::table('wingman_meetings', function (Blueprint $table) {
            // Why listening is paused when the person did not pause it: a rule of theirs ("1:1").
            $table->string('paused_reason')->nullable()->after('paused_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wingman_meetings', function (Blueprint $table) {
            $table->dropColumn('paused_reason');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('wingman_settings');
        });
    }
};
