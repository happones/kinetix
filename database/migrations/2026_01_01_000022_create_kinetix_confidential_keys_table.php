<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kinetix_confidential_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key_id')->unique();
            $table->string('driver');
            $table->text('wrapped_key');
            $table->boolean('is_current')->default(false)->index();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_confidential_keys');
    }
};
