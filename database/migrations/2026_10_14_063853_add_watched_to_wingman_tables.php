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
        Schema::table('wingman_segments', function (Blueprint $table) {
            // Already read by the Wingman agent.
            $table->boolean('watched')->default(false)->after('noted');
            $table->index(['wingman_meeting_id', 'final', 'watched']);
        });

        Schema::table('wingman_meetings', function (Blueprint $table) {
            // When the Wingman agent last read the call.
            $table->timestamp('watched_at')->nullable()->after('notes_updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wingman_meetings', function (Blueprint $table) {
            $table->dropColumn('watched_at');
        });

        Schema::table('wingman_segments', function (Blueprint $table) {
            $table->dropIndex(['wingman_meeting_id', 'final', 'watched']);
            $table->dropColumn('watched');
        });
    }
};
