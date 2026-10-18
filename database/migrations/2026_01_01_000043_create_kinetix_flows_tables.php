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
        // A bot flow: its trigger, the lines it runs on and its draft graph.
        Schema::create('kinetix_flows', function (Blueprint $table) {
            $table->id();
            HostKeys::team($table)->nullable()->index();
            $table->string('name');
            $table->string('status', 16)->default('draft'); // draft, active, inactive
            $table->json('trigger');                        // {type, keywords, match}
            $table->json('channel_ids')->nullable();        // null = every line
            $table->json('draft');                          // the graph being edited
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->timestamps();
        });

        // Each publish freezes the graph: a run finishes on the version it started.
        Schema::create('kinetix_flow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained('kinetix_flows')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('graph');
            HostKeys::user($table, 'published_by')->nullable();
            $table->timestamps();
        });

        // A flow running in a conversation.
        Schema::create('kinetix_flow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_id')->constrained('kinetix_flows')->cascadeOnDelete();
            $table->foreignId('flow_version_id')->constrained('kinetix_flow_versions')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('kinetix_conversations')->cascadeOnDelete();
            $table->string('status', 16);     // running, waiting_reply, waiting_delay, completed, handed_off, paused, failed
            $table->string('current_node')->nullable();
            $table->json('variables')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0); // invalid answers to the current question
            $table->timestamp('wait_until')->nullable()->index();
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'status']);
        });

        // Every node a run went through: the per-node statistics.
        Schema::create('kinetix_flow_run_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flow_run_id')->constrained('kinetix_flow_runs')->cascadeOnDelete();
            $table->foreignId('flow_version_id')->constrained('kinetix_flow_versions')->cascadeOnDelete();
            $table->string('node_id');
            $table->timestamp('created_at')->nullable();

            $table->index(['flow_version_id', 'node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kinetix_flow_run_steps');
        Schema::dropIfExists('kinetix_flow_runs');
        Schema::dropIfExists('kinetix_flow_versions');
        Schema::dropIfExists('kinetix_flows');
    }
};
