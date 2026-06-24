<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kinetix_member_provisions', function (Blueprint $table) {
            $table->id();

            // Nullable so the module works without teams. No FK constraints:
            // the host's `teams`/`users` schema is unknown to this package.
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('role');
            $table->unsignedBigInteger('invited_by')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            // One outstanding provision per email per team.
            $table->unique(['team_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_member_provisions');
    }
};
