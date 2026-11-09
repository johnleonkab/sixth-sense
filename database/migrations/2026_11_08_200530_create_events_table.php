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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('source');
            // Null for decisions and clock ticks that carry their slot in occurred_at.
            // Postgres treats those nulls as distinct, so they do not collapse together.
            $table->string('external_id')->nullable();
            $table->json('payload')->nullable();
            $table->json('normalized')->nullable();
            $table->foreignId('causation_id')->nullable()->constrained('events')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('recorded_at')->useCurrent();

            $table->unique(['user_id', 'source', 'type', 'external_id'], 'events_dedupe_unique');
            $table->index(['user_id', 'occurred_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION events_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'The event log is append-only.';
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER events_no_update
            BEFORE UPDATE OR DELETE ON events
            FOR EACH ROW EXECUTE FUNCTION events_append_only()
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');

        DB::unprepared('DROP FUNCTION IF EXISTS events_append_only()');
    }
};
