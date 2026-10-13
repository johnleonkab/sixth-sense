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
        Schema::create('agenda_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The alert it comes from, when it does (otherwise the person asked for it).
            $table->foreignId('inbox_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('origin');
            $table->string('title');
            $table->text('context')->nullable();
            // What the scheduler decided, and why.
            $table->string('status')->default('assessing');
            $table->string('kind')->nullable();
            $table->string('urgency')->nullable();
            $table->string('urgency_reason')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->date('deadline')->nullable();
            $table->json('attendees')->nullable();
            // Where it went.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('event_ref')->nullable();
            $table->string('event_url', 2048)->nullable();
            $table->foreignId('pending_action_id')->nullable()->constrained()->nullOnDelete();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agenda_tasks');
    }
};
