<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties each notification-preferences row to its notifiable's model TYPE as
 * well as its key, so the accounts of different credential profiles (a
 * `Client` #1 and a `User` #1) never share their opt-outs. The key stays
 * unique per type. Existing rows keep a null type and stay with the default
 * user model.
 *
 * Additive and idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kinetix_notification_preferences')
            || Schema::hasColumn('kinetix_notification_preferences', 'notifiable_type')) {
            return;
        }

        Schema::table('kinetix_notification_preferences', function (Blueprint $table): void {
            $table->string('notifiable_type')->nullable()->after('user_id');
        });

        Schema::table('kinetix_notification_preferences', function (Blueprint $table): void {
            $table->dropUnique(['user_id']);
            $table->unique(['user_id', 'notifiable_type']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('kinetix_notification_preferences', 'notifiable_type')) {
            return;
        }

        Schema::table('kinetix_notification_preferences', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'notifiable_type']);
            $table->unique('user_id');
        });

        Schema::table('kinetix_notification_preferences', function (Blueprint $table): void {
            $table->dropColumn('notifiable_type');
        });
    }
};
