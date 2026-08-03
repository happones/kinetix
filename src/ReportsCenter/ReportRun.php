<?php

declare(strict_types=1);

namespace Happones\Kinetix\ReportsCenter;

use Happones\Kinetix\Support\Concerns\ScopedToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single execution of a `Report` — one row per queued run, whether
 * launched ad-hoc or produced by a `ReportSchedule`.
 *
 * @property int                       $id
 * @property int|null                  $team_id
 * @property int|null                  $report_schedule_id
 * @property string                    $report_class
 * @property ReportRunStatus           $status
 * @property string                    $format
 * @property int                       $processed_rows
 * @property int|null                  $total_rows
 * @property int|null                  $percent
 * @property array<string, mixed>|null $parameters
 * @property string|null               $disk
 * @property string|null               $path
 * @property string|null               $file_name
 * @property string|null               $error_message
 * @property int|null                  $launched_by_id
 * @property Carbon|null               $expires_at
 * @property Carbon|null               $started_at
 * @property Carbon|null               $completed_at
 * @property Carbon|null               $cancelled_at
 */
class ReportRun extends Model
{
    use ScopedToTeam;

    public static function kinetixTeamModule(): string
    {
        return 'reports_center';
    }

    protected $table = 'kinetix_report_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status'       => ReportRunStatus::class,
            'parameters'   => 'array',
            'expires_at'   => 'datetime',
            'started_at'   => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReportSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportSchedule::class, 'report_schedule_id');
    }

    /**
     * The user who launched this run directly (null for a schedule-produced run).
     *
     * @return BelongsTo<Model, $this>
     */
    public function launchedBy(): BelongsTo
    {
        $guard    = config('auth.defaults.guard', 'web');
        $provider = config("auth.guards.{$guard}.provider", 'users');

        /** @var class-string<Model> $model */
        $model = config("auth.providers.{$provider}.model", 'App\\Models\\User');

        return $this->belongsTo($model, 'launched_by_id');
    }

    public function isDownloadable(): bool
    {
        return $this->status === ReportRunStatus::Completed
            && $this->path !== null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
