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
        Schema::create('wingman_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wingman_meeting_id')->constrained()->cascadeOnDelete();
            // slot, issue, page or message.
            $table->string('kind');
            $table->string('title');
            $table->text('body')->nullable();
            // Why it showed up: what was said that led to it.
            $table->string('why')->nullable();
            $table->text('quote')->nullable();
            $table->json('links')->nullable();
            // What it proposes to do ({type, label, payload}), made a pending action only when the person reviews it.
            $table->json('action')->nullable();
            $table->foreignId('pending_action_id')->nullable()->constrained()->nullOnDelete();
            $table->string('importance')->default('normal');
            // The same thing is never brought twice in a call.
            $table->string('dedupe_key');
            // up or down: whether it helped.
            $table->string('feedback')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['wingman_meeting_id', 'dedupe_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wingman_insights');
    }
};
