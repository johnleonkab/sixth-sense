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
        Schema::table('wingman_meetings', function (Blueprint $table) {
            // The living notes of the call: summary, topics, decisions, open questions, commitments.
            $table->json('notes')->nullable()->after('context_prepared_at');
            $table->timestamp('notes_updated_at')->nullable()->after('notes');
        });

        Schema::table('wingman_segments', function (Blueprint $table) {
            // Already taken into the notes.
            $table->boolean('noted')->default(false)->after('final');
            $table->index(['wingman_meeting_id', 'final', 'noted']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wingman_segments', function (Blueprint $table) {
            $table->dropIndex(['wingman_meeting_id', 'final', 'noted']);
            $table->dropColumn('noted');
        });

        Schema::table('wingman_meetings', function (Blueprint $table) {
            $table->dropColumn(['notes', 'notes_updated_at']);
        });
    }
};
