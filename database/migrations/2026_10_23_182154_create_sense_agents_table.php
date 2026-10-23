<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agents copied out of the work repository so the voice can use them
     * without reading GitHub on every turn.
     */
    public function up(): void
    {
        Schema::create('sense_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->text('purpose');
            $table->text('cue');
            $table->text('instructions');
            $table->string('source_path');
            $table->string('source_sha')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sense_agents');
    }
};
