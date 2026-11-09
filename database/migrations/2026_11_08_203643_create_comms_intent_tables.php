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
        Schema::create('comms_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('timezone')->default('Europe/Madrid');
            $table->string('locale', 16)->default('es');
            $table->string('work_start', 5)->default('09:00');
            $table->string('work_end', 5)->default('19:00');
            $table->json('work_days');
            $table->timestamps();
        });

        Schema::create('comms_owned_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('ref');
            $table->string('name');
            $table->json('keywords');
            $table->timestamps();

            $table->unique(['user_id', 'kind', 'ref']);
        });

        Schema::create('comms_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('what', 280);
            $table->string('state')->default('open');
            $table->string('settle_reason')->nullable();
            $table->date('deadline')->nullable();
            $table->string('deadline_text')->nullable();
            $table->text('expects')->nullable();
            $table->unsignedTinyInteger('importance')->default(0);
            $table->unsignedTinyInteger('importance_override')->nullable();
            $table->timestamp('importance_override_until')->nullable();
            $table->json('flags');
            $table->foreignId('opened_event_id')->nullable()->constrained('comms_events')->nullOnDelete();
            $table->foreignId('settled_event_id')->nullable()->constrained('comms_events')->nullOnDelete();
            $table->foreignId('last_change_event_id')->nullable()->constrained('comms_events')->nullOnDelete();
            $table->string('last_change_summary', 280)->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
            $table->index(['user_id', 'state']);
        });

        Schema::create('comms_intent_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('intent_id')->constrained('comms_intents')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('comms_people')->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();

            $table->unique(['intent_id', 'person_id', 'role']);
        });

        Schema::create('comms_conversation_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('intent_id')->constrained('comms_intents')->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('comms_messages')->cascadeOnDelete();
            $table->string('operation');
            $table->float('confidence')->default(0);
            $table->boolean('uncertain')->default(false);
            $table->foreignId('state_event_id')->nullable()->constrained('comms_events')->nullOnDelete();
            $table->timestamps();

            $table->unique(['intent_id', 'message_id']);
        });

        Schema::create('comms_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('comms_messages')->cascadeOnDelete();
            $table->foreignId('intent_id')->nullable()->constrained('comms_intents')->nullOnDelete();
            $table->foreignId('owned_area_id')->nullable()->constrained('comms_owned_areas')->nullOnDelete();
            $table->string('summary', 280);
            $table->unsignedTinyInteger('importance')->default(0);
            $table->timestamp('shown_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comms_signals');
        Schema::dropIfExists('comms_conversation_links');
        Schema::dropIfExists('comms_intent_participants');
        Schema::dropIfExists('comms_intents');
        Schema::dropIfExists('comms_owned_areas');
        Schema::dropIfExists('comms_preferences');
    }
};
