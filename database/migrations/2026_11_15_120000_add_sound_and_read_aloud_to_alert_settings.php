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
        Schema::table('alert_settings', function (Blueprint $table) {
            $table->string('sound')->nullable()->after('enabled');
            $table->boolean('read_aloud')->default(true)->after('sound');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('alert_settings', function (Blueprint $table) {
            $table->dropColumn(['sound', 'read_aloud']);
        });
    }
};
