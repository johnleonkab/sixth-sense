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
        Schema::table('wingman_meetings', function (Blueprint $table) {
            // Set while the person paused listening: what is said meanwhile is not kept.
            $table->timestamp('paused_at')->nullable()->after('left_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wingman_meetings', function (Blueprint $table) {
            $table->dropColumn('paused_at');
        });
    }
};
