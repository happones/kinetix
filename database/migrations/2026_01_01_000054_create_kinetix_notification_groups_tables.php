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
        // A team's named groups of members, the audience a notification route
        // can point at ("Support leads").
        Schema::create('kinetix_notification_groups', function (Blueprint $table) {
            $table->id();
            HostKeys::team($table)->nullable();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'name']);
        });

        Schema::create('kinetix_notification_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('kinetix_notification_groups')->cascadeOnDelete();
            // The member by model type AND key, so a Client #1 is not a User #1.
            $table->string('user_type', 191);
            HostKeys::user($table);
            $table->timestamps();

            $table->unique(['group_id', 'user_type', 'user_id'], 'kinetix_notification_group_members_unique');
            $table->index(['user_type', 'user_id'], 'kinetix_notification_group_members_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_notification_group_members');
        Schema::dropIfExists('kinetix_notification_groups');
    }
};
