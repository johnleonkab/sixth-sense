<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The knowledge base keeps an index, not a copy: the distinct words of
     * each document, a short summary only of the most used ones, and how
     * often each is opened. The text already copied is deleted, and every
     * document is indexed again on the next runs.
     */
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropFullText(['title', 'content']);
        });

        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropColumn('content');
            $table->longText('terms')->nullable()->after('source_updated_at');
            $table->text('summary')->nullable()->after('terms');
            $table->string('summarized_version', 100)->nullable()->after('summary');
            $table->unsignedInteger('views')->default(0)->after('summarized_version');
            $table->timestamp('last_viewed_at')->nullable()->after('views');
        });

        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->fullText(['title', 'terms'])->language('spanish');
        });

        DB::table('knowledge_documents')->update(['indexed_at' => null]);
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropFullText(['title', 'terms']);
        });

        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropColumn(['terms', 'summary', 'summarized_version', 'views', 'last_viewed_at']);
            $table->longText('content')->nullable();
        });

        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->fullText(['title', 'content'])->language('spanish');
        });
    }
};
