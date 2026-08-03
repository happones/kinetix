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
        Schema::create('kinetix_settings', function (Blueprint $table) {
            $table->id();

            // Nullable so the module works without teams (null = global scope).
            // No FK constraint: the host's teams schema is unknown to the package.
            HostKeys::team($table)->nullable()->index();
            $table->string('key');
            $table->text('value')->nullable();
            $table->boolean('encrypted')->default(false);
            $table->timestamps();

            // One value per key per scope.
            $table->unique(['team_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_settings');
    }
};
