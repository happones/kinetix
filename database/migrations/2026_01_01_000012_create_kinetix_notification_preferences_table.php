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
        Schema::create('kinetix_notification_preferences', function (Blueprint $table) {
            $table->id();
            HostKeys::user($table)->unique();
            // { type: { channel: bool } } — only stored opt-outs/overrides; an
            // absent type/channel defaults to enabled.
            $table->json('preferences')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_notification_preferences');
    }
};
