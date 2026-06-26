<?php

declare(strict_types=1);

namespace Happones\Kinetix\Activity;

use Happones\Kinetix\Data\ActivityData;
use Illuminate\Database\Eloquent\Model;

/**
 * Records and reads activity entries, scoped to the active team (or global when
 * teams are off). Recording dispatches {@see ActivityLogged} (the event spine).
 * Reads are always paginated — never load the whole log.
 */
class ActivityLogger
{
    /**
     * @param array<string, mixed> $properties
     */
    public function log(
        string $event,
        ?Model $subject = null,
        array $properties = [],
        ?Model $causer = null,
        ?string $description = null,
        string $logName = 'default',
    ): Activity {
        $causer ??= auth()->check() ? auth()->user() : null;

        $activity = Activity::create([
            'team_id'      => $this->teamId(),
            'log_name'     => $logName,
            'event'        => $event,
            'description'  => $description,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id'   => $subject?->getKey(),
            'causer_type'  => $causer !== null ? $causer::class : null,
            'causer_id'    => $causer?->getKey(),
            'properties'   => $properties,
        ]);

        ActivityLogged::dispatch($activity);

        return $activity;
    }

    /**
     * Paginated, team-scoped feed. Filter by subject (per-feature view) and/or
     * event. Eager-loads the causer to avoid N+1.
     *
     * @param  array<string, mixed>                                                                                                   $filters
     * @return array{data: array<int, ActivityData>, pagination: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function query(array $filters = []): array
    {
        $perPage = (int) ($filters['per_page'] ?? config('kinetix.activity.per_page', 15));
        $page    = (int) ($filters['page'] ?? 1);

        $query = Activity::query()
            ->with('causer')
            ->where('team_id', $this->teamId())
            ->when(! empty($filters['subject_type']), fn ($q) => $q->where('subject_type', $filters['subject_type']))
            ->when(isset($filters['subject_id']) && $filters['subject_id'] !== '', fn ($q) => $q->where('subject_id', $filters['subject_id']))
            ->when(! empty($filters['event']), fn ($q) => $q->where('event', $filters['event']))
            ->latest();

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $paginator->getCollection()
                ->map(static fn (Activity $activity): ActivityData => ActivityData::fromModel($activity))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ];
    }

    /**
     * Delete entries older than the given number of days. Returns the count.
     */
    public function prune(int $days): int
    {
        return (int) Activity::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }

    protected function teamId(): int|string|null
    {
        if (! config('kinetix.activity.teams', false)) {
            return null;
        }

        return auth()->user()?->currentTeam?->getKey();
    }
}
