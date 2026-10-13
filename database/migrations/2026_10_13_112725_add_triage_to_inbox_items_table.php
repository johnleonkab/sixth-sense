<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            // Every incoming message is listed at once; the AI then says whether it matters.
            $table->string('triage')->nullable()->after('status');
            // The reply engine is thinking a proposal through.
            $table->boolean('planning')->default(false)->after('triage');
        });

        // What was there before only held messages already judged important.
        DB::table('inbox_items')->whereIn('source', ['gmail', 'slack'])->update(['triage' => 'important']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inbox_items', function (Blueprint $table) {
            $table->dropColumn(['triage', 'planning']);
        });
    }
};
