<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Consumption counters per billable + metric key + period
        // ('YYYY-MM' calendar month; '' = lifetime counter).
        Schema::create('kinetix_usage', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('key');
            $table->string('period', 7)->default('');
            $table->unsignedBigInteger('used')->default(0);
            $table->timestamps();

            $table->unique(
                ['billable_type', 'billable_id', 'key', 'period'],
                'kinetix_usage_unique',
            );
        });

        // Top-up credit balances per billable + metric key — consumption
        // beyond the plan allowance draws these down. Not period-scoped:
        // purchased credits persist until consumed.
        Schema::create('kinetix_credits', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('key');
            $table->unsignedBigInteger('balance')->default(0);
            $table->timestamps();

            $table->unique(
                ['billable_type', 'billable_id', 'key'],
                'kinetix_credits_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_credits');
        Schema::dropIfExists('kinetix_usage');
    }
};
