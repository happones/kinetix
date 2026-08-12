<?php

declare(strict_types=1);

use Happones\Kinetix\Support\HostKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-announcement dismissals — what the banner needs and the feed's single
 * "last seen" timestamp cannot express: closing one banner must hide THAT
 * entry, not mark the whole feed read.
 *
 * `team_id` is copied from the announcement at dismiss time so a tenant's rows
 * can be listed and pruned with the tenant, without joining back to the
 * announcements table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kinetix_announcement_dismissals')) {
            return;
        }

        Schema::create('kinetix_announcement_dismissals', function (Blueprint $table): void {
            $table->id();
            HostKeys::user($table);
            $table->unsignedBigInteger('announcement_id');
            HostKeys::team($table)->nullable()->index();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            // Covers the banner's "not dismissed by me" existence check.
            $table->unique(['user_id', 'announcement_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_announcement_dismissals');
    }
};
