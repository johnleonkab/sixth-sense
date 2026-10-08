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
        Schema::create('inbox_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->string('external_id');
            $table->string('external_url', 2048)->nullable();
            $table->string('actor_name')->nullable();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('why');
            $table->unsignedTinyInteger('relevance')->default(0);
            $table->json('payload')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            // Sources may report the same thing twice: alert once.
            $table->unique(['user_id', 'source', 'external_id']);
            $table->index(['user_id', 'received_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbox_items');
    }
};
