<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An announcement had no way to end. "Maintenance on Sunday" stayed in the feed
 * forever, and every tenant's list only ever grew — so the feed slowly filled
 * with news that had stopped being news.
 *
 * `expires_at` NULL keeps the old behaviour (never expires), so existing rows
 * are unaffected. Additive and idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kinetix_announcements')
            || Schema::hasColumn('kinetix_announcements', 'expires_at')) {
            return;
        }

        Schema::table('kinetix_announcements', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->after('published_at')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('kinetix_announcements', 'expires_at')) {
            return;
        }

        Schema::table('kinetix_announcements', function (Blueprint $table): void {
            $table->dropIndex(['expires_at']);
            $table->dropColumn('expires_at');
        });
    }
};
