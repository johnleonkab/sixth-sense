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
        Schema::create('wingman_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wingman_meeting_id')->constrained()->cascadeOnDelete();
            // The extension's id for it: the same segment is sent again as it grows.
            $table->string('client_id');
            $table->string('speaker');
            $table->boolean('is_me')->default(false);
            $table->text('text');
            $table->boolean('final')->default(false);
            $table->timestamp('spoken_at');
            $table->timestamps();

            $table->unique(['wingman_meeting_id', 'client_id']);
            $table->index(['wingman_meeting_id', 'spoken_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wingman_segments');
    }
};
