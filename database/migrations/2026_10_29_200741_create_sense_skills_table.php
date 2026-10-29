<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Skills copied out of .claude/skills/{name}/SKILL.md in the work
     * repository, so a turn does not read GitHub to know which exist.
     */
    public function up(): void
    {
        Schema::create('sense_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description');
            $table->longText('instructions');
            $table->json('files')->nullable();
            $table->boolean('model_invocable')->default(true);
            $table->string('source_path');
            $table->string('source_sha')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sense_skills');
    }
};
