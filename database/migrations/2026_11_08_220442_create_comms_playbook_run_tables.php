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
        Schema::create('comms_playbook_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('playbook');
            $table->foreignId('intent_id')->nullable()->constrained('comms_intents')->nullOnDelete();
            $table->string('state');
            $table->string('step');
            $table->json('context');
            $table->foreignId('trigger_event_id')->nullable()->constrained('comms_events')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
            $table->index(['user_id', 'state']);
        });

        Schema::create('comms_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('title');
            $table->string('kind');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->json('attendees');
            $table->string('status')->default('confirmed');
            $table->text('agenda')->nullable();
            $table->boolean('recurring')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'external_id']);
            $table->index(['user_id', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comms_calendar_events');
        Schema::dropIfExists('comms_playbook_runs');
    }
};
