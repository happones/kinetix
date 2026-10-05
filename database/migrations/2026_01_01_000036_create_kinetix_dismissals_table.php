<?php

declare(strict_types=1);

use Happones\Kinetix\Support\HostKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which alerts each user closed for good — the state a `permanent` close needs
 * to follow the user to every device. One row per (user, key); `expires_at`
 * brings an alert back after a while ("remind me in 30 days").
 *
 * Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kinetix_dismissals')) {
            return;
        }

        Schema::create('kinetix_dismissals', function (Blueprint $table): void {
            $table->id();
            HostKeys::user($table);
            $table->string('key', 191);
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_dismissals');
    }
};
