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
        Schema::create('kinetix_webhook_endpoints', function (Blueprint $table) {
            $table->id();
            HostKeys::team($table)->nullable()->index();
            $table->string('name');
            $table->string('url');
            $table->string('secret');
            $table->json('events');          // subscribed event names
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_webhook_endpoints');
    }
};
