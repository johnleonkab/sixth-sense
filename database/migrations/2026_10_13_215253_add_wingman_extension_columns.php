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
        Schema::table('users', function (Blueprint $table) {
            // The browser extension's token, kept as a hash: shown once, revocable.
            $table->string('wingman_token_hash', 64)->nullable()->unique()->after('wingman_enabled');
            $table->timestamp('wingman_token_used_at')->nullable()->after('wingman_token_hash');
        });

        Schema::table('wingman_meetings', function (Blueprint $table) {
            // When the person joined and left the call (from the extension).
            $table->timestamp('joined_at')->nullable()->after('status');
            $table->timestamp('left_at')->nullable()->after('joined_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wingman_meetings', function (Blueprint $table) {
            $table->dropColumn(['joined_at', 'left_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['wingman_token_hash']);
            $table->dropColumn(['wingman_token_hash', 'wingman_token_used_at']);
        });
    }
};
