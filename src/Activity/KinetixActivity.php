<?php

declare(strict_types=1);

namespace Happones\Kinetix\Activity;

use Happones\Kinetix\Data\ActivityData;
use Illuminate\Database\Eloquent\Model;

/**
 * Static entry point for the Activity module:
 *
 *     KinetixActivity::log('exported', $report, ['format' => 'csv']);
 *     KinetixActivity::for($product);          // paginated entries for one record
 *
 * Models can auto-log create/update/delete with the
 * {@see Concerns\LogsKinetixActivity} trait.
 */
class KinetixActivity
{
    public static function logger(): ActivityLogger
    {
        return app(ActivityLogger::class);
    }

    /**
     * Record an entry. Returns the stored model (native or spatie), or null if
     * spatie logging is globally disabled.
     *
     * @param array<string, mixed> $properties
     */
    public static function log(
        string $event,
        ?Model $subject = null,
        array $properties = [],
        ?Model $causer = null,
        ?string $description = null,
        string $logName = 'default',
    ): ?Model {
        return static::logger()->log($event, $subject, $properties, $causer, $description, $logName);
    }

    /**
     * Paginated entries for a single subject record.
     *
     * @return array{data: array<int, ActivityData>, pagination: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public static function for(Model $subject, int $page = 1): array
    {
        return static::logger()->query([
            'subject_type' => $subject::class,
            'subject_id'   => $subject->getKey(),
            'page'         => $page,
        ]);
    }
}
