<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intent_lessons', function (Blueprint $table) {
            $table->string('layer', 20)->nullable()->after('inbox_item_id');
            $table->string('about', 120)->nullable()->after('layer');
            $table->string('topic', 40)->nullable()->after('about');
            $table->unsignedInteger('uses')->default(0)->after('body');
            $table->timestamp('last_used_at')->nullable()->after('uses');
            $table->foreignId('proposal_outcome_id')->nullable()->after('inbox_item_id')->constrained()->nullOnDelete();
            $table->index(['user_id', 'layer']);
        });

        Schema::table('proposal_outcomes', function (Blueprint $table) {
            $table->timestamp('learned_at')->nullable()->after('seconds_to_outcome');
        });
    }

    public function down(): void
    {
        Schema::table('proposal_outcomes', function (Blueprint $table) {
            $table->dropColumn('learned_at');
        });

        Schema::table('intent_lessons', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'layer']);
            $table->dropConstrainedForeignId('proposal_outcome_id');
            $table->dropColumn(['layer', 'about', 'topic', 'uses', 'last_used_at']);
        });
    }
};
