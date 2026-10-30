<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What agents produced (a view, a draft, a fact, a proposal), each under
     * its own reference: the voice shows it by that reference, never by its content.
     */
    public function up(): void
    {
        Schema::create('agent_productions', function (Blueprint $table) {
            $table->id();
            $table->string('ref', 40)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->string('summary', 300)->nullable();
            $table->json('canvas')->nullable();
            $table->json('beside')->nullable();
            $table->foreignId('pending_action_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_productions');
    }
};
