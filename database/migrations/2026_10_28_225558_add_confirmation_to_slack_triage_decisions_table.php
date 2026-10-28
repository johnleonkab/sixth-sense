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
        Schema::table('slack_triage_decisions', function (Blueprint $table) {
            $table->text('draft_text')->nullable()->after('candidate');
            $table->string('edited')->nullable()->after('draft_text');
            $table->string('autonomy')->nullable()->after('edited');
            $table->timestamp('confirmed_at')->nullable()->after('autonomy');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('slack_triage_decisions', function (Blueprint $table) {
            $table->dropColumn(['draft_text', 'edited', 'autonomy', 'confirmed_at']);
        });
    }
};
