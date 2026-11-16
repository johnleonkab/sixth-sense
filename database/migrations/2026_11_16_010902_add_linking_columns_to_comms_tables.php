<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns the decided design adds to existing tables
 * (docs/execution-plan.md 4.2): what the extractor and router store on an
 * intent, the link's segment and alternative, the window of a message, the
 * uncertain sources of a proposal, the topic of a signal, and the window and
 * run of each model call.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('comms_intents', function (Blueprint $table) {
            $table->string('kind', 24)->nullable()->after('what');
            $table->json('steps')->nullable()->after('expects');
            $table->json('unclear_fields')->nullable()->after('steps');
            $table->boolean('possible_ask')->default(false)->after('unclear_fields');
            $table->string('topic_subject', 191)->nullable()->after('possible_ask');
            $table->foreignId('alternative_intent_id')->nullable()->after('topic_subject')->constrained('comms_intents')->nullOnDelete();
            $table->timestamp('snoozed_until')->nullable()->after('alternative_intent_id');
            $table->boolean('from_backfill')->default(false)->after('snoozed_until');
            $table->string('changed_summary', 280)->nullable()->after('last_change_summary');
            $table->timestamp('changed_at')->nullable()->after('changed_summary');

            $table->index(['user_id', 'topic_subject']);
            $table->index(['user_id', 'possible_ask', 'state']);
        });

        Schema::table('comms_conversation_links', function (Blueprint $table) {
            $table->foreignId('segment_id')->nullable()->after('message_id')->constrained('comms_segments')->nullOnDelete();
            $table->json('alternative')->nullable()->after('uncertain');
            $table->foreignId('link_event_id')->nullable()->after('state_event_id')->constrained('comms_events')->nullOnDelete();
        });

        Schema::table('comms_messages', function (Blueprint $table) {
            $table->foreignId('window_id')->nullable()->after('thread_ref')->constrained('comms_windows')->nullOnDelete();
        });

        Schema::table('comms_proposals', function (Blueprint $table) {
            $table->json('uncertain_source_message_ids')->nullable()->after('source_event_ids');
        });

        Schema::table('comms_signals', function (Blueprint $table) {
            $table->string('topic_subject', 191)->nullable()->after('owned_area_id');
        });

        Schema::table('ai_usages', function (Blueprint $table) {
            $table->unsignedBigInteger('window_id')->nullable();
            $table->unsignedTinyInteger('run_index')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_usages', function (Blueprint $table) {
            $table->dropColumn(['window_id', 'run_index']);
        });

        Schema::table('comms_signals', function (Blueprint $table) {
            $table->dropColumn('topic_subject');
        });

        Schema::table('comms_proposals', function (Blueprint $table) {
            $table->dropColumn('uncertain_source_message_ids');
        });

        Schema::table('comms_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('window_id');
        });

        Schema::table('comms_conversation_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('segment_id');
            $table->dropConstrainedForeignId('link_event_id');
            $table->dropColumn('alternative');
        });

        Schema::table('comms_intents', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'topic_subject']);
            $table->dropIndex(['user_id', 'possible_ask', 'state']);
            $table->dropConstrainedForeignId('alternative_intent_id');
            $table->dropColumn(['kind', 'steps', 'unclear_fields', 'possible_ask', 'topic_subject', 'snoozed_until', 'from_backfill', 'changed_summary', 'changed_at']);
        });
    }
};
