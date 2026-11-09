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
        Schema::create('comms_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action_class');
            $table->json('conditions');
            $table->string('outcome');
            $table->timestamps();

            $table->index(['user_id', 'action_class']);
        });

        Schema::create('comms_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->unsignedBigInteger('run_id')->nullable();
            $table->foreignId('intent_id')->nullable()->constrained('comms_intents')->nullOnDelete();
            $table->string('action_class');
            $table->json('target');
            $table->json('payload');
            $table->json('source_event_ids');
            $table->json('verification')->nullable();
            $table->json('allows')->nullable();
            $table->boolean('states_product_facts')->default(false);
            $table->boolean('commits_date')->default(false);
            $table->boolean('user_initiated')->default(false);
            $table->string('policy_outcome')->nullable();
            $table->string('deny_reason')->nullable();
            $table->string('status')->default('pending');
            $table->string('external_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
            $table->index(['user_id', 'status']);
        });

        Schema::table('pending_actions', function (Blueprint $table) {
            $table->foreignId('proposal_id')->nullable()->unique()->constrained('comms_proposals')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pending_actions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proposal_id');
        });

        Schema::dropIfExists('comms_proposals');
        Schema::dropIfExists('comms_policies');
    }
};
