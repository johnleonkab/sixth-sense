<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The work repository's CLAUDE.md, copied so every turn can carry it.
     */
    public function up(): void
    {
        Schema::table('github_repositories', function (Blueprint $table) {
            $table->longText('memory')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('github_repositories', function (Blueprint $table) {
            $table->dropColumn('memory');
        });
    }
};
