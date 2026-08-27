<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two columns the password policy needs on the host's users table.
 *
 * - `password_changed_at` — what expiry counts from. NULL means "predates the
 *   policy", which is treated as current rather than expired, so switching the
 *   policy on cannot lock out every existing account at once.
 * - `must_change_password` — a forced change, set when an admin issues a
 *   temporary credential (or after a breach).
 *
 * Both are additive and nullable/defaulted, so an app that never enables the
 * module is unaffected by having run this.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'password_changed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('password_changed_at')->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'must_change_password')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('must_change_password')->default(false);
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['password_changed_at', 'must_change_password'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
