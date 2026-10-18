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
        // The team's saved answers, inserted in the inbox with "/shortcut".
        Schema::create('kinetix_quick_replies', function (Blueprint $table) {
            $table->id();
            HostKeys::team($table)->nullable()->index();
            $table->string('shortcut', 40);
            $table->text('body');
            HostKeys::user($table, 'created_by')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'shortcut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_quick_replies');
    }
};
