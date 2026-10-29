<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Markdown from agents/{slug}/references/, copied with the agent so a
     * call does not read GitHub.
     */
    public function up(): void
    {
        Schema::table('sense_agents', function (Blueprint $table) {
            $table->text('references')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sense_agents', function (Blueprint $table) {
            $table->dropColumn('references');
        });
    }
};
