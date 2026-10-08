<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties each password-history row to its model TYPE as well as its key, so the
 * accounts of different credential profiles (a `Client` #1 and a `User` #1)
 * never share a history. Existing rows keep a null type and stay with the
 * default user model.
 *
 * The profiles' models must share the user key type the history table was
 * created with (`kinetix.key_types.user`). Additive and idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kinetix_password_history')
            || Schema::hasColumn('kinetix_password_history', 'authenticatable_type')) {
            return;
        }

        Schema::table('kinetix_password_history', function (Blueprint $table): void {
            $table->string('authenticatable_type')->nullable()->after('user_id');
            $table->index(['user_id', 'authenticatable_type']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('kinetix_password_history', 'authenticatable_type')) {
            return;
        }

        Schema::table('kinetix_password_history', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'authenticatable_type']);
            $table->dropColumn('authenticatable_type');
        });
    }
};
