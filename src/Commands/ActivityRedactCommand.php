<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Activity\ActivityLogger;
use Happones\Kinetix\Activity\ActivityRedactor;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Redact the activity history at rest: rows written before activity
 * redaction existed still hold hidden, encrypted and confidential values in
 * their `old` / `attributes`. The feed already redacts them when it reads;
 * this rewrites the stored rows, for backups and anything else that reads
 * the table. Uses the same rule ({@see ActivityRedactor}); only rows that
 * change are written, through the query builder (no model events). Running
 * it again changes nothing.
 */
class ActivityRedactCommand extends Command
{
    protected $signature = 'kinetix:activity:redact
        {--chunk=500 : Rows processed per chunk}
        {--dry-run : Count the rows that would change without writing}';

    protected $description = 'Redact hidden, encrypted and confidential values stored in older Kinetix activity rows';

    public function handle(ActivityLogger $logger): int
    {
        /** @var class-string<Model> $class */
        $class  = $logger->activityModel();
        $model  = new $class;
        $key    = $model->getKeyName();
        $dryRun = (bool) $this->option('dry-run');
        $chunk  = max(1, (int) $this->option('chunk'));
        $count  = 0;

        $model->newQuery()->toBase()
            ->select([$key, 'subject_type', 'properties'])
            ->chunkById($chunk, function ($rows) use ($model, $key, $dryRun, &$count): void {
                foreach ($rows as $row) {
                    $redacted = $this->redacted($row->subject_type ?? null, $row->properties ?? null);

                    if ($redacted === null) {
                        continue;
                    }

                    $count++;

                    if (! $dryRun) {
                        $model->newQuery()->toBase()->where($key, $row->{$key})->update(['properties' => $redacted]);
                    }
                }
            }, $key);

        $this->info($dryRun
            ? "{$count} activity row(s) would be redacted."
            : "Redacted {$count} activity row(s).");

        return self::SUCCESS;
    }

    /**
     * The row's properties JSON with `old` / `attributes` redacted, or null
     * when nothing changes.
     */
    private function redacted(mixed $subjectType, mixed $properties): ?string
    {
        if (! is_string($properties) || $properties === '') {
            return null;
        }

        $decoded = json_decode($properties, true);

        if (! is_array($decoded)) {
            return null;
        }

        $type    = is_string($subjectType) ? $subjectType : null;
        $changed = false;

        foreach (['old', 'attributes'] as $part) {
            if (! isset($decoded[$part]) || ! is_array($decoded[$part])) {
                continue;
            }

            $redacted = ActivityRedactor::redactFor($type, $decoded[$part]);

            if ($redacted !== $decoded[$part]) {
                $decoded[$part] = $redacted;
                $changed        = true;
            }
        }

        return $changed ? (string) json_encode($decoded, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;
    }
}
