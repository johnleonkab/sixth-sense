<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One repository per person is the work archive. The rest stay a list.
     */
    public function up(): void
    {
        Schema::table('github_repositories', function (Blueprint $table) {
            $table->boolean('is_work')->default(false)->after('writes_code');
        });
    }

    public function down(): void
    {
        Schema::table('github_repositories', function (Blueprint $table) {
            $table->dropColumn('is_work');
        });
    }
};
