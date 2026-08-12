<?php

declare(strict_types=1);

use Happones\Kinetix\Support\HostKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the per-user "last seen the feed" row tenant-aware.
 *
 * The table held ONE row per user, so opening the feed inside team A also
 * cleared the unread badge for team B — a user in two teams could never see
 * team B's announcements as new. Read state is now one row per (user, team),
 * with `team_id` NULL holding the read state of the platform-wide entries so
 * they stop being new everywhere once the user has read them.
 *
 * Non-destructive: existing rows keep `team_id` NULL, which is exactly the
 * global row, so nothing re-appears as unread after the upgrade. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kinetix_announcement_views')
            || Schema::hasColumn('kinetix_announcement_views', 'team_id')) {
            return;
        }

        Schema::table('kinetix_announcement_views', function (Blueprint $table): void {
            HostKeys::team($table)->nullable()->after('user_id');
        });

        // The old single-column unique would reject the second team's row.
        if ($this->hasUniqueOnUserAlone()) {
            Schema::table('kinetix_announcement_views', function (Blueprint $table): void {
                $table->dropUnique(['user_id']);
            });
        }

        Schema::table('kinetix_announcement_views', function (Blueprint $table): void {
            $table->unique(['user_id', 'team_id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('kinetix_announcement_views', 'team_id')) {
            return;
        }

        Schema::table('kinetix_announcement_views', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'team_id']);
            $table->dropColumn('team_id');
        });

        Schema::table('kinetix_announcement_views', function (Blueprint $table): void {
            $table->unique(['user_id']);
        });
    }

    /**
     * Whether the legacy `user_id` unique index is still on the table — hosts
     * that hand-rolled the table may never have had it.
     */
    protected function hasUniqueOnUserAlone(): bool
    {
        foreach (Schema::getIndexes('kinetix_announcement_views') as $index) {
            if (($index['unique'] ?? false) && ($index['columns'] ?? []) === ['user_id']) {
                return true;
            }
        }

        return false;
    }
};
