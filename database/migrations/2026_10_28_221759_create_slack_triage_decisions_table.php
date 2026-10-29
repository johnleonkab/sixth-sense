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
        Schema::create('slack_triage_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id');
            $table->string('action');
            $table->string('place');
            $table->string('owner');
            $table->text('reason');
            $table->boolean('uncertain')->default(false);
            $table->boolean('has_target')->default(false);
            $table->string('target')->nullable();
            $table->boolean('already_answered')->default(false);
            $table->boolean('policy_present')->default(false);
            $table->string('thread_key')->nullable();
            $table->string('channel')->nullable();
            $table->json('candidate');
            $table->timestamp('promoted_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'external_id']);
            $table->index(['settled_at', 'place', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slack_triage_decisions');
    }
};
