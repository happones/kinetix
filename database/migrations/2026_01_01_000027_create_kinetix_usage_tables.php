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
        // Consumption counters per billable + metric key + period
        // ('YYYY-MM' calendar month; '' = lifetime counter).
        Schema::create('kinetix_usage', function (Blueprint $table) {
            $table->id();
            // The billable is the host's User or Team, so its id follows
            // kinetix.key_types.morph like every other host morph.
            $table->string('billable_type');
            HostKeys::morph($table, 'billable_id');
            $table->index(['billable_type', 'billable_id']);
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
            // The billable is the host's User or Team, so its id follows
            // kinetix.key_types.morph like every other host morph.
            $table->string('billable_type');
            HostKeys::morph($table, 'billable_id');
            $table->index(['billable_type', 'billable_id']);
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
