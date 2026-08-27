<?php

declare(strict_types=1);

use Happones\Kinetix\Credentials\KinetixIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns a login can resolve against, for apps whose people don't all have
 * an email address.
 *
 * Only the fields listed in `kinetix.credentials.identity.fields` are added, and
 * `email` is only relaxed when it has stopped being the only way in — so
 * publishing this on an app that never configured the module does nothing.
 *
 * The unique indexes survive the nullability: in MySQL and Postgres NULLs do
 * not collide, so any number of users may have no email while the ones that do
 * still cannot share it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fields = KinetixIdentity::fields();

        foreach (['username' => 32, 'phone' => 20] as $column => $length) {
            if (in_array($column, $fields, true) && ! Schema::hasColumn('users', $column)) {
                Schema::table('users', function (Blueprint $table) use ($column, $length) {
                    $table->string($column, $length)->nullable()->unique();
                });
            }
        }

        // Email stops being mandatory only once something else can identify a
        // person. Changing it while it is the sole identifier would let an
        // account be created that nobody can log into.
        if (in_array('email', $fields, true) && count($fields) > 1 && Schema::hasColumn('users', 'email')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('email')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['username', 'phone'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                Schema::table('users', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }

        // `email` is deliberately left nullable: reverting it would fail on any
        // row that has since been created without one.
    }
};
