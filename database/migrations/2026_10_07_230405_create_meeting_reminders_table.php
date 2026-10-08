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
        Schema::create('meeting_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The event, and the start it was planned for: a moved meeting is planned again.
            $table->string('event_key');
            $table->string('event_ref')->nullable();
            $table->string('title');
            $table->timestamp('starts_at');
            $table->timestamp('remind_at');
            $table->unsignedTinyInteger('lead_minutes');
            $table->boolean('needs_preparation')->default(false);
            $table->string('preparation')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'event_key']);
            $table->index(['notified_at', 'remind_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meeting_reminders');
    }
};
