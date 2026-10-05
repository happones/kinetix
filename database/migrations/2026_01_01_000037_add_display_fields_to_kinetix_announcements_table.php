<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an announcement shows beyond its text: whether the reader may close it
 * (a security notice shouldn't be closable), and an optional call to action
 * ("Read the guide" → /docs/new-export).
 *
 * Existing rows stay closable and get no button, which is how they behaved.
 * Additive and idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kinetix_announcements')) {
            return;
        }

        Schema::table('kinetix_announcements', function (Blueprint $table): void {
            if (! Schema::hasColumn('kinetix_announcements', 'dismissible')) {
                $table->boolean('dismissible')->default(true)->after('level');
            }

            if (! Schema::hasColumn('kinetix_announcements', 'action_label')) {
                $table->string('action_label', 80)->nullable()->after('dismissible');
            }

            if (! Schema::hasColumn('kinetix_announcements', 'action_url')) {
                $table->string('action_url', 2048)->nullable()->after('action_label');
            }
        });
    }

    public function down(): void
    {
        foreach (['action_url', 'action_label', 'dismissible'] as $column) {
            if (Schema::hasColumn('kinetix_announcements', $column)) {
                Schema::table('kinetix_announcements', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
