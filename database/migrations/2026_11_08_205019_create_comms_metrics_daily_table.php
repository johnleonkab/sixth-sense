<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comms_metrics_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('messages_in')->default(0);
            $table->unsignedInteger('dropped_by_rule')->default(0);
            $table->unsignedInteger('dropped_by_model')->default(0);
            $table->unsignedInteger('intents_opened')->default(0);
            $table->unsignedInteger('intents_settled')->default(0);
            $table->unsignedInteger('uncertain_links')->default(0);
            $table->json('corrections');
            $table->unsignedInteger('misses')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comms_metrics_daily');
    }
};
