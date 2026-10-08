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
        Schema::table('inbox_items', function (Blueprint $table) {
            // What the person has to do about it, and whether they did.
            $table->string('status')->default('pending')->after('relevance');
            $table->string('thread_key')->nullable()->after('external_id');
            $table->json('proposal')->nullable()->after('payload');
            $table->string('resolution')->nullable()->after('status');
            $table->timestamp('resolved_at')->nullable()->after('read_at');

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'thread_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
            $table->dropIndex(['user_id', 'thread_key']);
            $table->dropColumn(['status', 'thread_key', 'proposal', 'resolution', 'resolved_at']);
        });
    }
};
