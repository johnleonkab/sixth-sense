<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the system noticed it does not know (a meeting without a clear
     * purpose, an acronym nobody explained), the question it asks about it,
     * and the person's answer once given.
     */
    public function up(): void
    {
        Schema::create('knowledge_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('subject_key', 191);
            $table->string('subject', 255);
            $table->text('question');
            $table->json('context')->nullable();
            $table->string('status', 20)->default('open');
            $table->text('answer')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->timestamp('next_occurrence_at')->nullable();
            $table->foreignId('inbox_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('asked_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'kind', 'subject_key']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_gaps');
    }
};
