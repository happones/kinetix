<?php

declare(strict_types=1);

namespace Happones\Kinetix\Activity\Concerns;

use Happones\Kinetix\Activity\KinetixActivity;
use Happones\Kinetix\Confidential\Casts\ConfidentialCast;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;

/**
 * Add to an Eloquent model to auto-record create/update/delete activity (with an
 * old→new diff on updates). No-ops when the Activity module is disabled.
 *
 * Override {@see kinetixActivityIgnored()} to exclude attributes from the diff.
 * Hidden and encrypted attributes are recorded as `[redacted]`.
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
     * The recorded diff. Old values are the stored (raw) ones, never the
     * cast ones: a cast decrypts. Hidden and encrypted attributes keep their
     * key with the value `[redacted]` ({@see kinetixActivityRedacts()}).
     *
     * @return array<string, mixed>
     */
    protected function kinetixActivityProperties(string $event): array
    {
        $ignored = array_flip($this->kinetixActivityIgnored());

        if ($event === 'updated') {
            $new = array_diff_key($this->getChanges(), $ignored);

            return [
                'old'        => $this->redactKinetixActivity(array_intersect_key($this->getRawOriginal(), $new)),
                'attributes' => $this->redactKinetixActivity($new),
            ];
        }

        if ($event === 'created') {
            return ['attributes' => $this->redactKinetixActivity(array_diff_key($this->getAttributes(), $ignored))];
        }

        return [];
    }

    /**
     * @param  array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function redactKinetixActivity(array $attributes): array
    {
        foreach (array_keys($attributes) as $key) {
            if ($this->kinetixActivityRedacts((string) $key)) {
                $attributes[$key] = '[redacted]';
            }
        }

        return $attributes;
    }

    /**
     * Whether an attribute's value stays out of the log: hidden ones, and the
     * ones stored encrypted (`encrypted*` casts, the encrypted ArrayObject /
     * Collection casts, ConfidentialCast).
     */
    protected function kinetixActivityRedacts(string $key): bool
    {
        if (in_array($key, $this->getHidden(), true) || $this->isEncryptedCastable($key) || ConfidentialCast::appliesTo($this, $key)) {
            return true;
        }

        $cast = $this->getCasts()[$key] ?? null;

        if (! is_string($cast)) {
            return false;
        }

        $class = explode(':', $cast, 2)[0];

        return in_array($class, [AsEncryptedArrayObject::class, AsEncryptedCollection::class], true);
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
