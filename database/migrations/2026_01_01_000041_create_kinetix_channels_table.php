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
        Schema::create('kinetix_channels', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();     // the line in its webhook URL
            HostKeys::team($table)->nullable()->index();
            $table->string('provider', 32);          // whatsapp, telegram, …
            $table->string('name');
            $table->string('status', 16)->default('disconnected');
            $table->string('handle')->nullable();    // +5215512345678, @acme_bot
            $table->string('external_id')->nullable()->index(); // the provider's id for the line
            $table->text('credentials')->nullable(); // encrypted
            $table->string('webhook_secret', 64);
            $table->json('meta')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_channels');
    }
};
