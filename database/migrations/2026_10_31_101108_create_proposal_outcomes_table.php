<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What happened to each thing the system proposed: sent as it was, edited,
     * undone, discarded (and why), resolved or expired. What the pillars learn
     * from and are measured with.
     */
    public function up(): void
    {
        Schema::create('proposal_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('pending_action_id')->nullable()->constrained()->nullOnDelete();
            $table->string('class', 20)->nullable();
            $table->string('confidence', 10)->nullable();
            $table->string('action_type', 40)->nullable();
            $table->string('outcome', 20);
            $table->string('reason', 40)->nullable();
            $table->unsignedInteger('edit_size')->nullable();
            $table->json('detail')->nullable();
            $table->unsignedInteger('seconds_to_outcome')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'outcome', 'created_at']);
            $table->index(['inbox_item_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_outcomes');
    }
};
