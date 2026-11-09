<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comms_context_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('subject', 191);
            $table->string('attribute', 48);
            $table->json('value');
            $table->json('audiences');
            $table->string('origin', 16);
            $table->decimal('confidence', 3, 2)->default(1);
            $table->json('refs');
            $table->string('status', 16)->default('active');
            $table->boolean('sensitive')->default(false);
            $table->foreignId('event_id')->nullable()->constrained('comms_events')->nullOnDelete();
            $table->string('value_hash', 64);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'subject', 'attribute']);
            $table->index(['event_id', 'subject', 'attribute', 'value_hash'], 'comms_context_facts_event_value');
        });

        Schema::create('comms_context_uses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fact_id')->constrained('comms_context_facts')->cascadeOnDelete();
            $table->string('audience', 24);
            $table->string('model_call_ref')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->string('topic')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropColumn('topic');
        });

        Schema::dropIfExists('comms_context_uses');
        Schema::dropIfExists('comms_context_facts');
    }
};
