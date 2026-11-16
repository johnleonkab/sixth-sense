<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables for linking by conversation window (docs/execution-plan.md 4.1):
 * windows and segments, the linker's decisions, topic search, the user's
 * urgent rules, the golden set, the nightly change list, card views,
 * evaluation runs and the embeddings cache.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('comms_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 16);
            $table->string('group_ref');
            $table->string('kind', 16);
            $table->json('message_ids');
            $table->timestamp('opened_at');
            $table->timestamp('last_message_at');
            $table->foreignId('settled_event_id')->nullable()->constrained('comms_events')->nullOnDelete();
            $table->string('state', 16)->default('open');
            $table->timestamps();

            // One open window per group is kept by the settle buffer under the per-user lock.
            $table->index(['user_id', 'source', 'group_ref', 'state']);
            $table->index(['user_id', 'state', 'last_message_at']);
        });

        Schema::create('comms_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('window_id')->constrained('comms_windows')->cascadeOnDelete();
            $table->json('message_ids');
            $table->json('new_message_ids');
            $table->json('participants');
            $table->text('restated');
            $table->json('unresolved_refs');
            $table->boolean('unsegmented')->default(false);
            $table->foreignId('decision_event_id')->nullable()->constrained('comms_events')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'window_id']);
        });

        Schema::create('comms_link_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('segment_id')->constrained('comms_segments')->cascadeOnDelete();
            $table->string('topic_subject', 191)->nullable();
            $table->foreignId('intent_id')->nullable()->constrained('comms_intents')->nullOnDelete();
            $table->string('outcome', 24);
            $table->string('certainty', 16);
            $table->string('agreement', 8);
            $table->json('alternative')->nullable();
            $table->text('quote');
            $table->json('run_event_ids');
            $table->foreignId('event_id')->nullable()->constrained('comms_events')->nullOnDelete();
            $table->timestamps();

            $table->unique('segment_id');
            $table->index(['user_id', 'certainty', 'created_at']);
            $table->index(['user_id', 'topic_subject']);
        });

        Schema::create('comms_embeddings', function (Blueprint $table) {
            $table->id();
            $table->string('hash', 40);
            $table->string('model', 64);
            $table->json('vector');
            $table->timestamp('created_at')->nullable();

            $table->unique(['hash', 'model']);
        });

        Schema::create('comms_topic_search', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('topic_subject', 191);
            $table->text('document');
            $table->string('embedding_hash', 40)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'topic_subject']);

            // SQLite (local and tests) has no full-text index; search falls back to LIKE.
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->fullText('document');
            }
        });

        Schema::create('comms_topic_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('topic_subject', 191);
            $table->string('alias');
            $table->string('alias_normalized', 191);
            $table->timestamps();

            $table->unique(['user_id', 'alias_normalized', 'topic_subject'], 'comms_topic_aliases_alias_unique');
            $table->index(['user_id', 'topic_subject']);
        });

        Schema::create('comms_urgent_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('ref');
            $table->json('sources');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'kind', 'ref']);
        });

        Schema::create('comms_golden_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->foreignId('message_id')->nullable()->constrained('comms_messages')->nullOnDelete();
            $table->foreignId('segment_id')->nullable()->constrained('comms_segments')->nullOnDelete();
            $table->string('label', 32);
            $table->json('expected')->nullable();
            $table->json('evidence_event_ids');
            $table->json('people');
            $table->json('topics');
            $table->foreignId('source_event_id')->nullable()->constrained('comms_events')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'kind', 'label']);
            $table->unique(['user_id', 'kind', 'message_id', 'label']);
        });

        Schema::create('comms_reconcile_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('run_date');
            $table->string('op', 16);
            $table->json('payload');
            $table->text('quote')->nullable();
            $table->text('why')->nullable();
            $table->string('disposition', 16);
            $table->foreignId('decision_event_id')->nullable()->constrained('comms_events')->nullOnDelete();
            $table->foreignId('applied_event_id')->nullable()->constrained('comms_events')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'run_date']);
            $table->index(['user_id', 'disposition']);
        });

        Schema::create('comms_card_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('intent_id')->constrained('comms_intents')->cascadeOnDelete();
            $table->timestamp('seen_at');
            $table->timestamps();

            $table->unique(['user_id', 'intent_id']);
        });

        Schema::create('comms_eval_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16);
            $table->json('prompt_versions');
            $table->json('metrics');
            $table->boolean('passed')->nullable();
            $table->string('report_path')->nullable();
            $table->timestamps();

            $table->index(['kind', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comms_eval_runs');
        Schema::dropIfExists('comms_card_views');
        Schema::dropIfExists('comms_reconcile_changes');
        Schema::dropIfExists('comms_golden_labels');
        Schema::dropIfExists('comms_urgent_rules');
        Schema::dropIfExists('comms_topic_aliases');
        Schema::dropIfExists('comms_topic_search');
        Schema::dropIfExists('comms_embeddings');
        Schema::dropIfExists('comms_link_decisions');
        Schema::dropIfExists('comms_segments');
        Schema::dropIfExists('comms_windows');
    }
};
