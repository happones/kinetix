<?php

declare(strict_types=1);

namespace Happones\Kinetix\Queue;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Reads a small set of live queue-health metrics for the <KinetixQueueStats>
 * widget. When Laravel Horizon is installed it uses Horizon's repositories
 * (throughput, recent/failed counts, per-queue wait); otherwise it falls back to
 * driver queue sizes + the failed_jobs table. All Horizon access is guarded so
 * the widget works with or without it.
 */
class QueueMetrics
{
    /**
     * @return array{
     *     horizon: bool,
     *     status: string|null,
     *     throughput: int|null,
     *     recentJobs: int|null,
     *     failedJobs: int,
     *     queues: array<int, array{name: string, connection: string|null, size: int, wait: int|null}>
     * }
     */
    public function snapshot(): array
    {
        $horizon = $this->horizonAvailable();

        return [
            'horizon'    => $horizon,
            'status'     => $horizon ? $this->horizonStatus() : null,
            'throughput' => $horizon ? $this->throughput() : null,
            'recentJobs' => $horizon ? $this->recentJobs() : null,
            'failedJobs' => $this->failedJobs($horizon),
            'failed'     => $this->failed(),
            'queues'     => $horizon ? $this->horizonQueues() : $this->configuredQueues(),
        ];
    }

    /**
     * The most recent failed jobs (newest first), for the retry/delete list.
     * Reads Laravel's failed-job store, so it works with or without Horizon.
     *
     * @return array<int, array{id: int|string, connection: string|null, queue: string|null, name: string, failedAt: string|null}>
     */
    public function failed(int $limit = 10): array
    {
        try {
            if (! app()->bound('queue.failer')) {
                return [];
            }

            $jobs = collect(app('queue.failer')->all())
                ->sortByDesc('failed_at')
                ->take($limit)
                ->map(fn ($job): array => [
                    'id'         => $job->id,
                    'connection' => $job->connection ?? null,
                    'queue'      => $job->queue      ?? null,
                    'name'       => $this->jobName($job->payload ?? null),
                    'failedAt'   => isset($job->failed_at) ? (string) $job->failed_at : null,
                ])
                ->values()
                ->all();

            return $jobs;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Extract the job's display name from its serialized payload.
     */
    protected function jobName(?string $payload): string
    {
        if (! is_string($payload)) {
            return 'Job';
        }

        $decoded = json_decode($payload, true);

        if (is_array($decoded)) {
            $name = $decoded['displayName'] ?? ($decoded['data']['commandName'] ?? null);

            if (is_string($name) && $name !== '') {
                return class_basename($name);
            }
        }

        return 'Job';
    }

    /**
     * Re-queue a failed job by id. Returns false if there's no failed-job store.
     */
    public function retry(string $id): bool
    {
        if (! app()->bound('queue.failer')) {
            return false;
        }

        Artisan::call('queue:retry', ['id' => [$id]]);

        return true;
    }

    /**
     * Permanently delete a failed job by id.
     */
    public function forget(string $id): bool
    {
        if (! app()->bound('queue.failer')) {
            return false;
        }

        return (bool) app('queue.failer')->forget($id);
    }

    public function horizonAvailable(): bool
    {
        return class_exists('Laravel\Horizon\Horizon')
            && app()->bound('Laravel\Horizon\Contracts\MasterSupervisorRepository');
    }

    /**
     * 'running' | 'paused' | 'inactive'.
     */
    protected function horizonStatus(): string
    {
        try {
            $masters = app('Laravel\Horizon\Contracts\MasterSupervisorRepository')->all();

            if (empty($masters)) {
                return 'inactive';
            }

            foreach ($masters as $master) {
                if (($master->status ?? null) === 'paused') {
                    return 'paused';
                }
            }

            return 'running';
        } catch (Throwable) {
            return 'inactive';
        }
    }

    protected function throughput(): ?int
    {
        try {
            return (int) app('Laravel\Horizon\Contracts\MetricsRepository')->jobsProcessedPerMinute();
        } catch (Throwable) {
            return null;
        }
    }

    protected function recentJobs(): ?int
    {
        try {
            return (int) app('Laravel\Horizon\Contracts\JobRepository')->countRecent();
        } catch (Throwable) {
            return null;
        }
    }

    protected function failedJobs(bool $horizon): int
    {
        try {
            if ($horizon) {
                return (int) app('Laravel\Horizon\Contracts\JobRepository')->countFailed();
            }

            if (app()->bound('queue.failer')) {
                return count(app('queue.failer')->all());
            }
        } catch (Throwable) {
            // fall through to 0
        }

        return 0;
    }

    /**
     * @return array<int, array{name: string, connection: string|null, size: int, wait: int|null}>
     */
    protected function horizonQueues(): array
    {
        try {
            $workload = app('Laravel\Horizon\Contracts\WorkloadRepository')->get();

            return array_map(fn (array $queue): array => [
                'name'       => (string) ($queue['name'] ?? 'default'),
                'connection' => null,
                'size'       => (int) ($queue['length'] ?? 0),
                'wait'       => isset($queue['wait']) ? (int) $queue['wait'] : null,
            ], $workload);
        } catch (Throwable) {
            return $this->configuredQueues();
        }
    }

    /**
     * @return array<int, array{name: string, connection: string|null, size: int, wait: int|null}>
     */
    protected function configuredQueues(): array
    {
        $queues = [];

        foreach ((array) config('kinetix.queue.queues', []) as $entry) {
            $connection = $entry['connection'] ?? null;
            $name       = (string) ($entry['queue'] ?? 'default');

            $queues[] = [
                'name'       => $name,
                'connection' => $connection,
                'size'       => $this->queueSize($connection, $name),
                'wait'       => null,
            ];
        }

        return $queues;
    }

    protected function queueSize(?string $connection, string $queue): int
    {
        try {
            return Queue::connection($connection)->size($queue);
        } catch (Throwable) {
            return 0;
        }
    }
}
