<?php

declare(strict_types=1);

use Happones\Kinetix\Support\HostKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Previously-used password HASHES, so "you can't reuse your last N passwords"
 * can be answered without anyone — including an operator with database access —
 * being able to read what those passwords were.
 *
 * No foreign key: the host's `users` schema is unknown to this package. Rows are
 * removed when the user is deleted (see PasswordObserver) and pruned to the
 * configured depth on every change, so the table stays bounded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kinetix_password_history', function (Blueprint $table) {
            $table->id();
            HostKeys::user($table)->index();
            $table->string('password');
            $table->timestamp('created_at')->nullable();

            // The lookup is always "this user's newest N".
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_password_history');
    }
};
