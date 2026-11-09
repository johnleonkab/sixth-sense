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
        Schema::rename('events', 'comms_events');

        Schema::table('comms_events', function (Blueprint $table) {
            $table->string('revision', 64)->default('');
            $table->boolean('sensitive')->default(false);
            $table->timestamp('processed_at')->nullable();

            $table->dropUnique('events_dedupe_unique');
            $table->unique(
                ['user_id', 'source', 'type', 'external_id', 'revision'],
                'comms_events_dedupe_unique',
            );
            $table->index(['user_id', 'processed_at']);
            $table->index(['user_id', 'type', 'occurred_at']);
        });

        // processed_at is the only column the consumer may set. Postgres json
        // columns are not equality-comparable, so the row is compared as jsonb.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION events_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'The event log is append-only.';
                END IF;

                IF to_jsonb(NEW) - 'processed_at' IS DISTINCT FROM to_jsonb(OLD) - 'processed_at' THEN
                    RAISE EXCEPTION 'The event log is append-only.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION events_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'The event log is append-only.';
            END;
            $$ LANGUAGE plpgsql
        SQL);

        Schema::table('comms_events', function (Blueprint $table) {
            $table->dropUnique('comms_events_dedupe_unique');
            $table->dropIndex(['user_id', 'processed_at']);
            $table->dropIndex(['user_id', 'type', 'occurred_at']);
            $table->dropColumn(['revision', 'sensitive', 'processed_at']);
            $table->unique(['user_id', 'source', 'type', 'external_id'], 'events_dedupe_unique');
        });

        Schema::rename('comms_events', 'events');
    }
};
