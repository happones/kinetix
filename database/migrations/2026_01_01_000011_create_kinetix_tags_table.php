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
        Schema::create('kinetix_tags', function (Blueprint $table) {
            $table->id();
            HostKeys::team($table)->nullable()->index();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();

            $table->unique(['team_id', 'slug']);
        });

        Schema::create('kinetix_taggables', function (Blueprint $table) {
            $table->unsignedBigInteger('tag_id');
            $table->string('taggable_type');
            HostKeys::morph($table, 'taggable_id');

            $table->index(['taggable_type', 'taggable_id']);
            $table->unique(['tag_id', 'taggable_type', 'taggable_id'], 'kinetix_taggables_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_taggables');
        Schema::dropIfExists('kinetix_tags');
    }
};
