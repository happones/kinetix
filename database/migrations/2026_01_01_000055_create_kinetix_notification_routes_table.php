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
        // Who receives a notification type, and on which channels: one row per
        // team, type and audience (a member, a role, a group, or everyone
        // eligible). `audience_id` is '' for the eligible audience, so the
        // unique key holds for it too.
        Schema::create('kinetix_notification_routes', function (Blueprint $table) {
            $table->id();
            HostKeys::team($table)->nullable();
            $table->string('type', 191);
            $table->string('audience_type', 20);
            $table->string('audience_id', 191)->default('');
            $table->json('channels');
            $table->timestamps();

            $table->unique(['team_id', 'type', 'audience_type', 'audience_id'], 'kinetix_notification_routes_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_notification_routes');
    }
};
