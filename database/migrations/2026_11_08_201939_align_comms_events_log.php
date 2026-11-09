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

        // processed_at is the only column the consumer may set.
        $this->allowProcessedAt();
    }

    /**
     * Deletes stay forbidden. An update may change processed_at and nothing else.
     */
    private function allowProcessedAt(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS events_no_update');
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER events_no_update
                BEFORE UPDATE ON comms_events
                FOR EACH ROW
                BEGIN
                    IF NOT (
                        NEW.id <=> OLD.id
                        AND NEW.user_id <=> OLD.user_id
                        AND NEW.type <=> OLD.type
                        AND NEW.source <=> OLD.source
                        AND NEW.external_id <=> OLD.external_id
                        AND NEW.revision <=> OLD.revision
                        AND NEW.sensitive <=> OLD.sensitive
                        AND NEW.payload <=> OLD.payload
                        AND NEW.normalized <=> OLD.normalized
                        AND NEW.causation_id <=> OLD.causation_id
                        AND NEW.occurred_at <=> OLD.occurred_at
                        AND NEW.recorded_at <=> OLD.recorded_at
                    ) THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The event log is append-only.';
                    END IF;
                END
            SQL);

            return;
        }

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
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS events_no_update');
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER events_no_update
                BEFORE UPDATE ON comms_events
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The event log is append-only.'
            SQL);
        } else {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION events_append_only() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'The event log is append-only.';
                END;
                $$ LANGUAGE plpgsql
            SQL);
        }

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
