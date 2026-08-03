<?php

declare(strict_types=1);

namespace Happones\Kinetix\Activity;

use Happones\Kinetix\Data\ActivityData;
use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\ActivitylogServiceProvider;

/**
 * Records and reads activity, scoped to the active team. It writes through the
 * configured driver: `spatie/laravel-activitylog` when installed (the preferred,
 * standard store), otherwise the native `kinetix_activity` table. Either way the
 * read side normalizes to the same {@see ActivityData} DTO, so the frontend is
 * driver-agnostic. Recording dispatches {@see ActivityLogged} (the event spine).
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
    ): ?Model {
        $causer ??= auth()->check() ? auth()->user() : null;
        $teamId = $this->teamId();

        $activity = $this->usesSpatie()
            ? $this->logViaSpatie($event, $subject, $properties, $causer, $description, $logName, $teamId)
            : $this->logViaNative($event, $subject, $properties, $causer, $description, $logName, $teamId);

        if ($activity !== null) {
            ActivityLogged::dispatch($activity);
        }

        return $activity;
    }

    /**
     * Paginated, team-scoped feed (causer eager-loaded → no N+1).
     *
     * @param  array<string, mixed>                                                                                                   $filters
     * @return array{data: array<int, ActivityData>, pagination: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function query(array $filters = []): array
    {
        $perPage = (int) ($filters['per_page'] ?? config('kinetix.activity.per_page', 15));
        $page    = (int) ($filters['page'] ?? 1);

        $paginator = $this->usesSpatie()
            ? $this->queryViaSpatie($filters, $perPage, $page)
            : $this->queryViaNative($filters, $perPage, $page);

        return [
            'data' => $paginator->getCollection()
                ->map(static fn (Model $activity): ActivityData => ActivityData::fromModel($activity))
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
     * Delete entries older than the given number of days. With the spatie driver
     * this delegates to `activitylog:clean` (the host's single source of truth).
     */
    public function prune(int $days): int
    {
        if ($this->usesSpatie()) {
            Artisan::call('activitylog:clean', ['--days' => $days]);

            return 0;
        }

        return (int) Activity::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();
    }

    /**
     * Whether the spatie driver is active (config `auto` + spatie installed, or
     * forced via `spatie`).
     */
    public function usesSpatie(): bool
    {
        $driver = (string) config('kinetix.activity.driver', 'auto');

        return match ($driver) {
            'native' => false,
            'spatie' => true,
            default  => class_exists(ActivitylogServiceProvider::class),
        };
    }

    protected function teamId(): int|string|null
    {
        return KinetixTeams::keyFor('activity');
    }

    /**
     * @param array<string, mixed> $properties
     */
    protected function logViaNative(
        string $event,
        ?Model $subject,
        array $properties,
        ?Model $causer,
        ?string $description,
        string $logName,
        int|string|null $teamId,
    ): Model {
        return Activity::create([
            'team_id'      => $teamId,
            'log_name'     => $logName,
            'event'        => $event,
            'description'  => $description,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id'   => $subject?->getKey(),
            'causer_type'  => $causer?->getMorphClass(),
            'causer_id'    => $causer?->getKey(),
            'properties'   => $properties,
        ]);
    }

    /**
     * Logs through spatie. The team is carried inside `properties.team_id`, so no
     * change to spatie's own schema is required.
     *
     * @param array<string, mixed> $properties
     */
    protected function logViaSpatie(
        string $event,
        ?Model $subject,
        array $properties,
        ?Model $causer,
        ?string $description,
        string $logName,
        int|string|null $teamId,
    ): ?Model {
        if ($teamId !== null) {
            $properties['team_id'] = $teamId;
        }

        $logger = activity($logName);

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        if ($causer !== null) {
            $logger->causedBy($causer);
        }

        $activity = $logger->withProperties($properties)->event($event)->log($description ?? $event);

        return $activity instanceof Model ? $activity : null;
    }

    /**
     * @param array<string, mixed> $filters
     */
    protected function queryViaNative(array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        return $this->applyFilters(Activity::query(), $filters)
            ->where('team_id', $this->teamId())
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param array<string, mixed> $filters
     */
    protected function queryViaSpatie(array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $model  = $this->spatieModel();
        $teamId = $this->teamId();

        return $this->applyFilters($model::query(), $filters)
            ->when(
                $teamId === null,
                fn ($query) => $query->whereNull('properties->team_id'),
                fn ($query) => $query->where('properties->team_id', $teamId),
            )
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  Builder<covariant Model> $query
     * @param  array<string, mixed>     $filters
     * @return Builder<covariant Model>
     */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->with('causer')
            ->when(! empty($filters['subject_type']), fn ($q) => $q->where('subject_type', $filters['subject_type']))
            ->when(isset($filters['subject_id']) && $filters['subject_id'] !== '', fn ($q) => $q->where('subject_id', $filters['subject_id']))
            ->when(! empty($filters['event']), fn ($q) => $q->where('event', $filters['event']));
    }

    /**
     * @return class-string<Model>
     */
    protected function spatieModel(): string
    {
        $model = config('activitylog.activity_model');

        if (is_string($model) && $model !== '' && is_subclass_of($model, Model::class)) {
            return $model;
        }

        return \Spatie\Activitylog\Models\Activity::class;
    }
}
