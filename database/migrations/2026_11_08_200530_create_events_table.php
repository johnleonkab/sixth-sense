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
        // A failed MySQL deploy can leave the table behind without recording
        // the migration. Creating it again would stop the next attempt.
        if (! Schema::hasTable('events') && ! Schema::hasTable('comms_events')) {
            Schema::create('events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('type');
                $table->string('source');
                // Null for decisions and clock ticks that carry their slot in occurred_at.
                // Nulls stay distinct, so they do not collapse together.
                $table->string('external_id')->nullable();
                $table->json('payload')->nullable();
                $table->json('normalized')->nullable();
                $table->foreignId('causation_id')->nullable()->constrained('events')->nullOnDelete();
                $table->timestamp('occurred_at');
                $table->timestamp('recorded_at')->useCurrent();

                $table->unique(['user_id', 'source', 'type', 'external_id'], 'events_dedupe_unique');
                $table->index(['user_id', 'occurred_at']);
            });
        }

        if (Schema::hasTable('events')) {
            $this->forbidChanges('events');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS events_append_only()');
        }
    }

    /**
     * Refuse every update and delete. processed_at is opened later.
     */
    private function forbidChanges(string $table): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS events_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS events_no_delete');
            DB::unprepared(<<<SQL
                CREATE TRIGGER events_no_update
                BEFORE UPDATE ON {$table}
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The event log is append-only.'
            SQL);
            DB::unprepared(<<<SQL
                CREATE TRIGGER events_no_delete
                BEFORE DELETE ON {$table}
                FOR EACH ROW
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The event log is append-only.'
            SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION events_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'The event log is append-only.';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::unprepared("DROP TRIGGER IF EXISTS events_no_update ON {$table}");
        DB::unprepared(<<<SQL
            CREATE TRIGGER events_no_update
            BEFORE UPDATE OR DELETE ON {$table}
            FOR EACH ROW EXECUTE FUNCTION events_append_only()
        SQL);
    }
};
