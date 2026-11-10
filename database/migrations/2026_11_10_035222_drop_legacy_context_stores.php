<?php

use App\Comms\Context\LegacyImport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The fact store replaces lessons, the nightly digest, the Slack attention
     * profile, the preference wording and the comms documents. What they hold
     * is copied into it first, then they go.
     */
    public function up(): void
    {
        app(LegacyImport::class)->run();

        Schema::dropIfExists('intent_lessons');
        Schema::dropIfExists('slack_attention_profiles');
        Schema::dropIfExists('comms_documents');

        Schema::table('work_contexts', function (Blueprint $table) {
            $table->dropColumn(['summary', 'digest']);
        });

        Schema::table('slack_preference_rules', function (Blueprint $table) {
            $table->dropColumn('body');
        });
    }

    public function down(): void
    {
        Schema::table('slack_preference_rules', function (Blueprint $table) {
            $table->text('body')->nullable()->after('words');
        });

        Schema::table('work_contexts', function (Blueprint $table) {
            $table->text('summary')->nullable()->after('status');
            $table->json('digest')->nullable()->after('summary');
        });

        Schema::create('comms_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ref');
            $table->string('kind');
            $table->string('title');
            $table->text('body');
            $table->string('topic')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'ref']);
        });

        Schema::create('slack_attention_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->json('signals');
            $table->timestamp('built_at');
            $table->timestamps();
        });

        Schema::create('intent_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('proposal_outcome_id')->nullable()->constrained()->nullOnDelete();
            $table->string('layer', 20)->nullable();
            $table->string('about', 120)->nullable();
            $table->string('topic', 40)->nullable();
            $table->text('body');
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
            $table->index(['user_id', 'layer']);
        });
    }
};
