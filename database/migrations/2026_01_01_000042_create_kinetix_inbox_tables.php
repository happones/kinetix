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
        // Who answers a line: agents see only the conversations of their lines.
        Schema::create('kinetix_channel_agents', function (Blueprint $table) {
            $table->foreignId('channel_id')->constrained('kinetix_channels')->cascadeOnDelete();
            HostKeys::user($table);
            $table->timestamps();

            $table->primary(['channel_id', 'user_id']);
        });

        // A line talking with one contact.
        Schema::create('kinetix_conversations', function (Blueprint $table) {
            $table->id();
            HostKeys::team($table)->nullable()->index();
            $table->foreignId('channel_id')->constrained('kinetix_channels')->cascadeOnDelete();
            $table->string('contact_key');             // the provider's id: a phone number, a chat id
            $table->string('contact_name')->nullable();
            $table->boolean('is_group')->default(false);
            $table->string('contactable_type')->nullable();
            HostKeys::morph($table, 'contactable_id')->nullable();
            HostKeys::user($table, 'assigned_to')->nullable()->index();
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'contact_key']);
        });

        Schema::create('kinetix_conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('kinetix_conversations')->cascadeOnDelete();
            $table->string('direction', 8);            // in, out, note
            $table->string('type', 16)->default('text');
            $table->text('body')->nullable();
            $table->string('media_path')->nullable();
            $table->string('media_mime')->nullable();
            $table->string('media_name')->nullable();
            $table->unsignedBigInteger('media_size')->nullable();
            $table->string('media_ref')->nullable();    // what the driver downloads the file by
            $table->string('status', 16)->default('received'); // received, pending, sent, delivered, read, failed
            $table->text('error')->nullable();
            $table->string('external_id')->nullable();
            $table->unsignedBigInteger('reply_to_id')->nullable();
            HostKeys::user($table, 'author_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
            $table->unique(['conversation_id', 'external_id']);
        });

        // Each user's own read cursor, so one agent opening a chat doesn't
        // read it for everyone.
        Schema::create('kinetix_conversation_reads', function (Blueprint $table) {
            $table->foreignId('conversation_id')->constrained('kinetix_conversations')->cascadeOnDelete();
            HostKeys::user($table);
            $table->unsignedBigInteger('last_read_message_id');
            $table->timestamps();

            $table->primary(['conversation_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_conversation_reads');
        Schema::dropIfExists('kinetix_conversation_messages');
        Schema::dropIfExists('kinetix_conversations');
        Schema::dropIfExists('kinetix_channel_agents');
    }
};
