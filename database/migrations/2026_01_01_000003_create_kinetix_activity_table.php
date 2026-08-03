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
        Schema::create('kinetix_activity', function (Blueprint $table) {
            $table->id();

            // null team = global scope. No FK: host teams schema is unknown.
            HostKeys::team($table)->nullable()->index();
            $table->string('log_name')->nullable();
            $table->string('event')->nullable();
            $table->text('description')->nullable();

            // Polymorphic subject (the record that changed) + causer (who did it).
            $table->string('subject_type')->nullable();
            HostKeys::morph($table, 'subject_id')->nullable();
            $table->string('causer_type')->nullable();
            HostKeys::morph($table, 'causer_id')->nullable();

            // { old: {...}, attributes: {...} } diff (and any custom props).
            $table->json('properties')->nullable();

            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['causer_type', 'causer_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_activity');
    }
};
