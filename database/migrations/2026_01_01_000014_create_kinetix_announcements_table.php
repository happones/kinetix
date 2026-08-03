<?php

declare(strict_types=1);

use Happones\Kinetix\Support\HostKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kinetix_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('level')->default('info');   // info | feature | fix
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });

        // One row per user holding the last time they opened the feed; anything
        // published after this is "new" to them.
        Schema::create('kinetix_announcement_views', function (Blueprint $table) {
            $table->id();
            HostKeys::user($table)->unique();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_announcement_views');
        Schema::dropIfExists('kinetix_announcements');
    }
};
