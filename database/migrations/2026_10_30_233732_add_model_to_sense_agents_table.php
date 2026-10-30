<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The model an Archive agent asks for in its front matter (`model:`), as Claude Code reads it.
     */
    public function up(): void
    {
        Schema::table('sense_agents', function (Blueprint $table) {
            $table->string('model', 120)->nullable()->after('skills');
        });
    }

    public function down(): void
    {
        Schema::table('sense_agents', function (Blueprint $table) {
            $table->dropColumn('model');
        });
    }
};
