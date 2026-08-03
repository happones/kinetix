<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes announcements tenant-aware: `team_id` is nullable and NULL means
 * **global** — a platform-wide announcement every team's feed shows, which is
 * how the product-update use case is meant to work.
 *
 * Non-destructive: existing rows stay NULL, so they remain visible to everyone
 * after the upgrade. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kinetix_announcements')
            || Schema::hasColumn('kinetix_announcements', 'team_id')) {
            return;
        }

        Schema::table('kinetix_announcements', function (Blueprint $table): void {
            $table->unsignedBigInteger('team_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('kinetix_announcements', 'team_id')) {
            return;
        }

        Schema::table('kinetix_announcements', function (Blueprint $table): void {
            $table->dropIndex(['team_id']);
            $table->dropColumn('team_id');
        });
    }
};
