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
        Schema::create('kinetix_report_schedules', function (Blueprint $table) {
            $table->id();
            HostKeys::team($table)->nullable()->index();
            $table->string('report_class');
            $table->string('frequency');
            $table->json('parameters')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            HostKeys::user($table, 'created_by_id')->nullable()->index();
            $table->boolean('notify_on_completion')->default(false);
            $table->timestamps();

            $table->index(['enabled', 'next_run_at']);
            $table->index('report_class');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_report_schedules');
    }
};
