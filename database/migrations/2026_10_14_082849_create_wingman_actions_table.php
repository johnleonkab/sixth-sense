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
        Schema::create('wingman_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wingman_meeting_id')->constrained()->cascadeOnDelete();
            // The card it came from, when it came from one.
            $table->foreignId('wingman_insight_id')->nullable()->constrained()->nullOnDelete();
            // meeting, message, issue or task.
            $table->string('kind');
            $table->string('title');
            $table->text('detail')->nullable();
            // The pending action it becomes when the person reviews it: type, label and payload.
            $table->string('type');
            $table->string('label');
            $table->json('payload');
            $table->foreignId('pending_action_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('dismissed_at')->nullable();
            $table->string('dedupe_key');
            $table->timestamps();

            $table->unique(['wingman_meeting_id', 'dedupe_key']);
        });

        Schema::table('wingman_meetings', function (Blueprint $table) {
            // When Wingman closed the meeting's document: final notes and follow-ups.
            $table->timestamp('closed_at')->nullable()->after('watched_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wingman_meetings', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });

        Schema::dropIfExists('wingman_actions');
    }
};
