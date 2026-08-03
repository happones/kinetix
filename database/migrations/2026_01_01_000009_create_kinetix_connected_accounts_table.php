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
        Schema::create('kinetix_connected_accounts', function (Blueprint $table) {
            $table->id();
            HostKeys::user($table)->index();
            $table->string('provider');               // 'github', 'google', …
            $table->string('provider_id');            // the provider's user id
            $table->string('nickname')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->text('avatar')->nullable();
            $table->text('token')->nullable();        // encrypted at rest
            $table->text('refresh_token')->nullable(); // encrypted at rest
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // One identity per provider, and one account per provider per user.
            $table->unique(['provider', 'provider_id']);
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_connected_accounts');
    }
};
