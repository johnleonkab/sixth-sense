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
        Schema::create('wingman_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The event and the start it was found at: a moved meeting is a new one.
            $table->string('event_key');
            $table->string('event_ref')->nullable();
            // The Meet code (abc-defg-hij): how the browser extension finds it.
            $table->string('meeting_code')->nullable();
            $table->string('meeting_url');
            $table->string('title');
            $table->string('account')->nullable();
            $table->string('organizer')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->json('attendees')->nullable();
            $table->text('description')->nullable();
            // scheduled, live or ended.
            $table->string('status')->default('scheduled');
            // What was gathered before the call: issues, pages, documents, recent emails.
            $table->json('context')->nullable();
            $table->timestamp('context_prepared_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'event_key']);
            $table->index(['user_id', 'meeting_code']);
            $table->index(['user_id', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wingman_meetings');
    }
};
