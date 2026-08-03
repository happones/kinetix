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
        Schema::create('kinetix_wizard_completions', function (Blueprint $table) {
            $table->id();
            HostKeys::user($table)->index();
            HostKeys::team($table)->nullable()->index();
            $table->string('slug')->index();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'team_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_wizard_completions');
    }
};
