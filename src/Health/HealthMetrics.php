<?php

declare(strict_types=1);

namespace Happones\Kinetix\Health;

use Throwable;

/**
 * Reads the latest stored health-check results from spatie/laravel-health for the
 * <KinetixHealthStatus> widget. All access is guarded so the widget reports
 * "unavailable" gracefully when the package isn't installed or no checks have run
 * yet. Derives an overall status (worst-of) from the individual checks.
 */
class HealthMetrics
{
    /**
     * @return array{
     *     available: bool,
     *     status: string|null,
     *     checkedAt: string|null,
     *     checks: array<int, array{name: string, label: string, status: string, message: string|null}>
     * }
     */
    public function snapshot(): array
    {
        if (! $this->available()) {
            return ['available' => false, 'status' => null, 'checkedAt' => null, 'checks' => []];
        }

        try {
            $results = app('Spatie\Health\ResultStores\ResultStore')->latestResults();

            if ($results === null) {
                return ['available' => true, 'status' => null, 'checkedAt' => null, 'checks' => []];
            }

            $checks = collect($results->storedCheckResults)
                ->map(fn ($result): array => [
                    'name'    => (string) ($result->name ?? ''),
                    'label'   => (string) ($result->label ?? $result->name ?? ''),
                    'status'  => (string) ($result->status ?? 'ok'),
                    'message' => $this->message($result),
                ])
                ->values()
                ->all();

            return [
                'available' => true,
                'status'    => $this->overallStatus($checks),
                'checkedAt' => $this->checkedAt($results),
                'checks'    => $checks,
            ];
        } catch (Throwable) {
            return ['available' => false, 'status' => null, 'checkedAt' => null, 'checks' => []];
        }
    }

    public function available(): bool
    {
        return class_exists('Spatie\Health\Health')
            && app()->bound('Spatie\Health\ResultStores\ResultStore');
    }

    protected function message(object $result): ?string
    {
        $summary = $result->shortSummary ?? null;

        if (is_string($summary) && $summary !== '') {
            return $summary;
        }

        $notification = $result->notificationMessage ?? null;

        return is_string($notification) && $notification !== '' ? $notification : null;
    }

    /**
     * Worst-of across the checks: failed/crashed > warning > ok.
     *
     * @param array<int, array{status: string}> $checks
     */
    protected function overallStatus(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        if (in_array('failed', $statuses, true) || in_array('crashed', $statuses, true)) {
            return 'failed';
        }

        if (in_array('warning', $statuses, true)) {
            return 'warning';
        }

        return 'ok';
    }

    protected function checkedAt(object $results): ?string
    {
        try {
            $finishedAt = $results->finishedAt ?? null;

            return $finishedAt !== null ? $finishedAt->toIso8601String() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
