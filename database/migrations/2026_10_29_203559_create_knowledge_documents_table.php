<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A local copy of the work archive, the Jira issues the person takes part
     * in and the Notion pages they shared, searchable with the database's own
     * full-text index.
     */
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20);
            $table->string('ref', 191);
            $table->string('title', 500);
            $table->text('url')->nullable();
            $table->string('version', 100)->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->longText('content')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'source', 'ref']);
            $table->index(['user_id', 'source', 'indexed_at']);
            $table->fullText(['title', 'content'])->language('spanish');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
