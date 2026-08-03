<?php

declare(strict_types=1);

namespace Happones\Kinetix\ReportsCenter;

use Happones\Kinetix\Support\Concerns\ScopedToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A recurring (or one-off, delayed) definition of when to run a `Report` —
 * each firing creates a `ReportRun` row. Distinct from `ReportRun` the same
 * way a cron entry is distinct from one of its executions.
 *
 * @property int                       $id
 * @property int|null                  $team_id
 * @property string                    $report_class
 * @property ReportFrequency           $frequency
 * @property array<string, mixed>|null $parameters
 * @property bool                      $enabled
 * @property Carbon|null               $next_run_at
 * @property Carbon|null               $last_run_at
 * @property int|null                  $created_by_id
 * @property bool                      $notify_on_completion
 */
class ReportSchedule extends Model
{
    use ScopedToTeam;

    public static function kinetixTeamModule(): string
    {
        return 'reports_center';
    }

    protected $table = 'kinetix_report_schedules';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'frequency'            => ReportFrequency::class,
            'parameters'           => 'array',
            'enabled'              => 'bool',
            'next_run_at'          => 'datetime',
            'last_run_at'          => 'datetime',
            'notify_on_completion' => 'bool',
        ];
    }

    /**
     * @return HasMany<ReportRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(ReportRun::class);
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function createdBy(): BelongsTo
    {
        $guard    = config('auth.defaults.guard', 'web');
        $provider = config("auth.guards.{$guard}.provider", 'users');

        /** @var class-string<Model> $model */
        $model = config("auth.providers.{$provider}.model", 'App\\Models\\User');

        return $this->belongsTo($model, 'created_by_id');
    }

    /**
     * Instantiate the underlying `Report` this schedule runs.
     */
    public function report(): Report
    {
        /** @var class-string<Report> $class */
        $class = $this->report_class;

        return new $class;
    }
}
