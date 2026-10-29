<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agents now come from .claude/agents/*.md, as in Claude Code: a name,
     * a description that says when to use it, and the skills it preloads.
     */
    public function up(): void
    {
        Schema::table('sense_agents', function (Blueprint $table) {
            $table->renameColumn('purpose', 'description');
        });

        Schema::table('sense_agents', function (Blueprint $table) {
            $table->dropColumn(['cue', 'references']);
            $table->json('skills')->nullable()->after('instructions');
        });
    }

    public function down(): void
    {
        Schema::table('sense_agents', function (Blueprint $table) {
            $table->dropColumn('skills');
            $table->text('cue')->nullable();
            $table->text('references')->nullable();
        });

        Schema::table('sense_agents', function (Blueprint $table) {
            $table->renameColumn('description', 'purpose');
        });
    }
};
