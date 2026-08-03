<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes mail templates tenant-aware the same *hybrid* way roles are: `team_id`
 * is nullable, and NULL means **global** — a platform default every team sees.
 *
 * Uniqueness moves from `key` to `(team_id, key)` so a team can hold its own
 * override of a global template under the same key; the resolver prefers the
 * team's row and falls back to the global one.
 *
 * Non-destructive by design: rows that exist when this runs keep `team_id` NULL,
 * so they stay visible everywhere and nothing disappears from the UI after the
 * upgrade. Idempotent — skipped when the column is already there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kinetix_mail_templates')
            || Schema::hasColumn('kinetix_mail_templates', 'team_id')) {
            return;
        }

        Schema::table('kinetix_mail_templates', function (Blueprint $table): void {
            $table->unsignedBigInteger('team_id')->nullable()->after('id')->index();

            $table->dropUnique('kinetix_mail_templates_key_unique');
            $table->unique(['team_id', 'key']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('kinetix_mail_templates', 'team_id')) {
            return;
        }

        Schema::table('kinetix_mail_templates', function (Blueprint $table): void {
            $table->dropUnique(['team_id', 'key']);
            $table->dropIndex(['team_id']);
            $table->dropColumn('team_id');
            $table->unique('key');
        });
    }
};
