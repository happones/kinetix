<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attributes each logged API request to a tenant.
 *
 * Unlike mail templates and announcements, NULL here is **not** a shared
 * default: a log row belongs to exactly one tenant, and a NULL row is simply
 * unattributed — written before this migration, or by a request with no team
 * context. The viewer scopes strictly, so unattributed rows never surface inside
 * a team; they age out with `kinetix:api-logs:prune`.
 *
 * Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kinetix_api_logs')
            || Schema::hasColumn('kinetix_api_logs', 'team_id')) {
            return;
        }

        Schema::table('kinetix_api_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('team_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('kinetix_api_logs', 'team_id')) {
            return;
        }

        Schema::table('kinetix_api_logs', function (Blueprint $table): void {
            $table->dropIndex(['team_id']);
            $table->dropColumn('team_id');
        });
    }
};
