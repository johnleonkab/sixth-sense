<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional keys this person uses instead of the system ones.
     * Ciphertext length varies, so the columns are text.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('gemini_api_key')->nullable();
            $table->text('deepseek_api_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['gemini_api_key', 'deepseek_api_key']);
        });
    }
};
