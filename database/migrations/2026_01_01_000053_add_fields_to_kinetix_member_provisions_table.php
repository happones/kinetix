<?php

declare(strict_types=1);

use Happones\Kinetix\Membership\MemberFields;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom member fields ({@see MemberFields}):
 * the values an admin entered while the member is still pending, kept as JSON
 * until activation hands them to the host and clears the column.
 *
 * Published with the `kinetix-membership-migrations` tag; only needed when
 * `membership.fields` is configured. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kinetix_member_provisions') || Schema::hasColumn('kinetix_member_provisions', 'fields')) {
            return;
        }

        Schema::table('kinetix_member_provisions', function (Blueprint $table): void {
            $table->json('fields')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('kinetix_member_provisions') || ! Schema::hasColumn('kinetix_member_provisions', 'fields')) {
            return;
        }

        Schema::table('kinetix_member_provisions', function (Blueprint $table): void {
            $table->dropColumn('fields');
        });
    }
};
