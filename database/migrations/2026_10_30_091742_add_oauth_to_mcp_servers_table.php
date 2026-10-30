<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MCP servers can also be connected by signing in (OAuth 2.1, as the MCP
     * authorization spec defines it): the client sixth-sense registered or
     * was given, the endpoints found, and the tokens, all encrypted.
     */
    public function up(): void
    {
        Schema::table('mcp_servers', function (Blueprint $table) {
            $table->string('auth', 10)->default('token')->after('token');
            $table->text('oauth')->nullable()->after('auth');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_servers', function (Blueprint $table) {
            $table->dropColumn(['auth', 'oauth']);
        });
    }
};
