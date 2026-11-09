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
        Schema::create('comms_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('display_name');
            $table->string('relation')->default('unknown');
            $table->unsignedTinyInteger('importance_weight')->default(5);
            $table->string('team')->nullable();
            $table->string('role')->nullable();
            $table->boolean('is_bot')->default(false);
            $table->foreignId('merged_into_id')->nullable()->constrained('comms_people')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('comms_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('comms_people')->cascadeOnDelete();
            $table->string('source');
            $table->string('external_id');
            $table->string('email')->nullable();
            $table->string('handle')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'source', 'external_id']);
            $table->index(['user_id', 'email']);
        });

        Schema::create('comms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->unique()->constrained('comms_events')->cascadeOnDelete();
            $table->string('source');
            $table->string('external_id');
            $table->string('channel_ref')->nullable();
            $table->string('thread_ref')->nullable();
            $table->foreignId('author_person_id')->constrained('comms_people')->cascadeOnDelete();
            $table->json('recipient_person_ids');
            $table->string('direction');
            $table->longText('text');
            $table->json('links');
            $table->json('attachments');
            $table->json('flags');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['user_id', 'source', 'external_id']);
            $table->index(['user_id', 'channel_ref', 'occurred_at']);
        });

        Schema::create('comms_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('slack_channel_ids');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comms_scopes');
        Schema::dropIfExists('comms_messages');
        Schema::dropIfExists('comms_identities');
        Schema::dropIfExists('comms_people');
    }
};
