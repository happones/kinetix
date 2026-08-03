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
        Schema::create('kinetix_comments', function (Blueprint $table) {
            $table->id();
            HostKeys::user($table)->index();          // author
            $table->string('commentable_type');
            HostKeys::morph($table, 'commentable_id');
            $table->unsignedBigInteger('parent_id')->nullable()->index(); // threaded replies
            $table->text('body');
            $table->timestamps();

            $table->index(['commentable_type', 'commentable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_comments');
    }
};
