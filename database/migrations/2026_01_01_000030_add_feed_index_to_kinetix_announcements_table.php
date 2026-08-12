<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The feed reads `where team_id = ? or team_id is null order by published_at
 * desc` on every request. Two single-column indexes can't serve that — the
 * database filters on one and sorts the rest by hand. This composite one does
 * both, and stays useful as a tenant's history grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kinetix_announcements')
            || ! Schema::hasColumn('kinetix_announcements', 'team_id')
            || $this->hasFeedIndex()) {
            return;
        }

        Schema::table('kinetix_announcements', function (Blueprint $table): void {
            $table->index(['team_id', 'published_at'], 'kinetix_announcements_feed_index');
        });
    }

    public function down(): void
    {
        if (! $this->hasFeedIndex()) {
            return;
        }

        Schema::table('kinetix_announcements', function (Blueprint $table): void {
            $table->dropIndex('kinetix_announcements_feed_index');
        });
    }

    protected function hasFeedIndex(): bool
    {
        if (! Schema::hasTable('kinetix_announcements')) {
            return false;
        }

        foreach (Schema::getIndexes('kinetix_announcements') as $index) {
            if (($index['name'] ?? '') === 'kinetix_announcements_feed_index') {
                return true;
            }
        }

        return false;
    }
};
