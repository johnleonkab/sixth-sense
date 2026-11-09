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
        Schema::table('ai_usages', function (Blueprint $table) {
            $table->string('tier')->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->json('event_ids')->nullable();
            $table->json('output')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_usages', function (Blueprint $table) {
            $table->dropColumn(['tier', 'prompt_version', 'event_ids', 'output']);
        });
    }
};
