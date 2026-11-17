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
        Schema::create('comms_intent_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_intent_id')->constrained('comms_intents')->cascadeOnDelete();
            $table->foreignId('to_intent_id')->constrained('comms_intents')->cascadeOnDelete();
            $table->string('kind');
            $table->timestamps();

            $table->unique(['user_id', 'from_intent_id', 'to_intent_id', 'kind'], 'comms_intent_links_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comms_intent_links');
    }
};
