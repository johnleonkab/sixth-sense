<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Databases that already created the table index the address itself.
     * MySQL cannot do that for a text column, so uniqueness moves to a hash.
     */
    public function up(): void
    {
        if (Schema::hasColumn('push_subscriptions', 'endpoint_hash')) {
            return;
        }

        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->string('endpoint_hash', 64)->nullable();
        });

        foreach (DB::table('push_subscriptions')->orderBy('id')->cursor() as $row) {
            DB::table('push_subscriptions')->where('id', $row->id)->update([
                'endpoint_hash' => hash('sha256', $row->endpoint),
            ]);
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('alter table push_subscriptions alter column endpoint_hash set not null');
        } else {
            DB::statement('alter table push_subscriptions modify endpoint_hash varchar(64) not null');
        }

        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->unique('endpoint_hash');
        });

        if (Schema::hasIndex('push_subscriptions', ['endpoint'], 'unique')) {
            Schema::table('push_subscriptions', function (Blueprint $table) {
                $table->dropUnique(['endpoint']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('push_subscriptions', 'endpoint_hash')) {
            return;
        }

        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropUnique(['endpoint_hash']);
            $table->dropColumn('endpoint_hash');
        });
    }
};
