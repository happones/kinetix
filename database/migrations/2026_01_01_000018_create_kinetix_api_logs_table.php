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
        Schema::create('kinetix_api_logs', function (Blueprint $table) {
            $table->id();
            HostKeys::user($table)->nullable()->index();
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->string('token_name')->nullable();
            $table->string('method', 10);
            $table->string('path', 2048);
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('ip', 45)->nullable();
            $table->json('request_body')->nullable();
            $table->text('response_body')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_api_logs');
    }
};
