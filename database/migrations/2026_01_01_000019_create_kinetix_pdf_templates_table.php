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
        Schema::create('kinetix_pdf_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            HostKeys::team($table)->nullable();
            $table->json('settings');
            $table->timestamps();

            $table->unique(['key', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_pdf_templates');
    }
};
