<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a member be provisioned by something other than an email address.
 *
 * `email` becomes optional and joins `username` and `phone` as one of the
 * identifiers a provision can carry — for the businesses whose staff simply
 * do not have an email address.
 *
 * Each identifier keeps its own "one outstanding provision per team" index.
 * NULLs do not collide in MySQL or Postgres, so a team can hold any number of
 * provisions with no email while the ones that have one still cannot duplicate
 * it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kinetix_member_provisions', function (Blueprint $table) {
            if (! Schema::hasColumn('kinetix_member_provisions', 'username')) {
                $table->string('username', 32)->nullable()->after('email');
                $table->unique(['team_id', 'username']);
            }

            if (! Schema::hasColumn('kinetix_member_provisions', 'phone')) {
                $table->string('phone', 20)->nullable()->after('username');
                $table->unique(['team_id', 'phone']);
            }
        });

        // Email stops being mandatory now that something else can identify the
        // person. The existing ['team_id', 'email'] unique index is untouched
        // and keeps working — it simply stops applying to rows without one.
        Schema::table('kinetix_member_provisions', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('kinetix_member_provisions', function (Blueprint $table) {
            foreach (['username', 'phone'] as $column) {
                if (Schema::hasColumn('kinetix_member_provisions', $column)) {
                    $table->dropUnique(['team_id', $column]);
                    $table->dropColumn($column);
                }
            }
        });

        // `email` is deliberately left nullable: reverting it would fail on any
        // provision created without one.
    }
};
