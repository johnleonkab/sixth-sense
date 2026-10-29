<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A scene is one compound request: a read, then the proposals that depend on it.
     */
    public function up(): void
    {
        Schema::create('scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('status');
            $table->string('origin');
            $table->json('input');
            $table->json('canvas');
            $table->timestamps();
        });

        Schema::create('scene_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scene_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('layer');
            $table->string('key');
            $table->string('status');
            $table->json('payload');
            $table->text('error')->nullable();
            $table->foreignId('pending_action_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['scene_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scene_steps');
        Schema::dropIfExists('scenes');
    }
};
