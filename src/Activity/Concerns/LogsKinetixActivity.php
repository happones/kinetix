<?php

declare(strict_types=1);

namespace Happones\Kinetix\Activity\Concerns;

use Happones\Kinetix\Activity\KinetixActivity;

/**
 * Add to an Eloquent model to auto-record create/update/delete activity (with an
 * old→new diff on updates). No-ops when the Activity module is disabled.
 *
 * Override {@see kinetixActivityIgnored()} to exclude attributes from the diff.
 */
trait LogsKinetixActivity
{
    public static function bootLogsKinetixActivity(): void
    {
        static::created(static fn ($model) => $model->recordKinetixActivity('created'));
        static::updated(static fn ($model) => $model->recordKinetixActivity('updated'));
        static::deleted(static fn ($model) => $model->recordKinetixActivity('deleted'));
    }

    public function recordKinetixActivity(string $event): void
    {
        if (! config('kinetix.activity.enabled', false)) {
            return;
        }

        KinetixActivity::log($event, $this, $this->kinetixActivityProperties($event));
    }

    /**
     * @return array<string, mixed>
     */
    protected function kinetixActivityProperties(string $event): array
    {
        $ignored = array_flip($this->kinetixActivityIgnored());

        if ($event === 'updated') {
            $new = array_diff_key($this->getChanges(), $ignored);

            return [
                'old'        => array_intersect_key($this->getOriginal(), $new),
                'attributes' => $new,
            ];
        }

        if ($event === 'created') {
            return ['attributes' => array_diff_key($this->getAttributes(), $ignored)];
        }

        return [];
    }

    /**
     * Attributes excluded from the recorded diff.
     *
     * @return array<int, string>
     */
    protected function kinetixActivityIgnored(): array
    {
        return ['created_at', 'updated_at', 'password', 'remember_token'];
    }
}
