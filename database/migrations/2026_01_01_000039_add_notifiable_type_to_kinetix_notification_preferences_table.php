<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties each notification-preferences row to its notifiable's model TYPE as
 * well as its key, so the accounts of different credential profiles (a
 * `Client` #1 and a `User` #1) never share their opt-outs. Existing rows keep
 * a null type and stay with the default user model.
 *
 * Adds the column only; `000040` moves the unique key onto key + type.
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
    }

    public function down(): void
    {
        if (! Schema::hasColumn('kinetix_notification_preferences', 'notifiable_type')) {
            return;
        }

        Schema::table('kinetix_notification_preferences', function (Blueprint $table): void {
            $table->dropColumn('notifiable_type');
        });
    }
};
