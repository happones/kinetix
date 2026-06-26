<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kinetix_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('webhook_endpoint_id')->index();
            $table->string('event');
            $table->json('payload')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->boolean('success')->default(false);
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->text('response')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_webhook_logs');
    }
};
