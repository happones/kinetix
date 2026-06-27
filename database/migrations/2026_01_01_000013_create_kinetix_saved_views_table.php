<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kinetix_saved_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('view_key');      // which table the view belongs to
            $table->string('name');
            $table->json('state');           // { search, sort, direction, perPage, filters, columns }
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'view_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_saved_views');
    }
};
