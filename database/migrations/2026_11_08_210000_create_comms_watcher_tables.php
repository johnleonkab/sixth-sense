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
        Schema::create('comms_watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('intent_id')->constrained('comms_intents')->cascadeOnDelete();
            $table->string('key');
            $table->string('condition_type');
            $table->json('condition');
            $table->timestamp('deadline_at')->nullable();
            $table->unsignedTinyInteger('attempt')->default(0);
            $table->string('on_fire')->nullable();
            $table->string('state')->default('active');
            $table->timestamps();

            $table->unique(['user_id', 'key']);
            $table->index(['user_id', 'state', 'deadline_at']);
        });

        Schema::create('comms_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('intent_id')->nullable()->constrained('comms_intents')->nullOnDelete();
            $table->string('key');
            $table->text('text');
            $table->json('context');
            $table->json('options');
            $table->json('answer')->nullable();
            $table->foreignId('answered_event_id')->nullable()->constrained('comms_events')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });

        Schema::create('comms_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('kind');
            $table->string('urgency');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('source_event_ids');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->string('feedback')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comms_notifications');
        Schema::dropIfExists('comms_questions');
        Schema::dropIfExists('comms_watchers');
    }
};
