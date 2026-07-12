<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kinetix_report_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->unsignedBigInteger('report_schedule_id')->nullable()->index();
            $table->string('report_class')->index();
            $table->string('status')->default('pending')->index();
            $table->string('format');
            $table->unsignedBigInteger('processed_rows')->default(0);
            $table->unsignedBigInteger('total_rows')->nullable();
            $table->unsignedTinyInteger('percent')->nullable();
            $table->json('parameters')->nullable();
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('file_name')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('launched_by_id')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_report_runs');
    }
};
