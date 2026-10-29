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
        Schema::create('ai_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // What it was for (alerts.judge, wingman.notes…), and which model of its chain answered or failed.
            $table->string('task');
            $table->string('provider');
            $table->string('model');
            // ok, unavailable (quota, down, slow) or invalid (not what was asked).
            $table->string('status');
            $table->unsignedInteger('input_tokens')->default(0);
            // Of the input, what the provider served from its cache (cheaper).
            $table->unsignedInteger('cached_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            // In dollars, from the prices in config/ai.php (null when the model has none).
            $table->decimal('cost', 12, 8)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['task', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_usages');
    }
};
