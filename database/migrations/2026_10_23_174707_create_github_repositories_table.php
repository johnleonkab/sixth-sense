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
        Schema::create('github_repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connected_account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('github_id');
            $table->string('owner');
            $table->string('name');
            $table->string('full_name');
            $table->boolean('is_private')->default(false);
            $table->string('default_branch')->default('main');
            $table->boolean('can_push')->default(false);
            $table->boolean('included')->default(false);
            $table->boolean('writes_code')->default(false);
            $table->timestamps();

            $table->unique(['connected_account_id', 'github_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('github_repositories');
    }
};
