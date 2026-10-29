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
        Schema::create('slack_preference_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('slack_triage_decision_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pending_action_id')->nullable()->constrained()->nullOnDelete();
            $table->string('correction');
            $table->string('status');
            $table->string('place')->nullable();
            $table->string('channel')->nullable();
            $table->string('actor')->nullable();
            $table->string('target')->nullable();
            $table->json('words')->nullable();
            $table->text('body');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slack_preference_rules');
    }
};
