<?php

declare(strict_types=1);

namespace Happones\Kinetix\Gdpr;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Collects a user's registered data sections (for "download my data") and
 * purges their account (anonymize / delete / custom handler).
 */
class GdprManager
{
    public function __construct(protected GdprRegistry $registry) {}

    /**
     * Resolve every registered section for the given user into a plain array.
     *
     * @return array<string, mixed>
     */
    public function collect(Model $user): array
    {
        $data = [];

        foreach ($this->registry->sections() as $name => $resolver) {
            $value = $resolver($user);

            $data[$name] = $value instanceof Arrayable
                ? $value->toArray()
                : $value;
        }

        return $data;
    }

    /**
     * Delete or anonymize the user's account per config / custom handler.
     */
    public function purge(Model $user): void
    {
        $callback = $this->registry->deleteCallback();

        if ($callback instanceof Closure) {
            $callback($user);

            return;
        }

        if (config('kinetix.gdpr.deletion', 'anonymize') === 'delete') {
            $user->delete();

            return;
        }

        $this->anonymize($user);
    }

    protected function anonymize(Model $user): void
    {
        /** @var array<string, mixed> $map */
        $map = (array) config('kinetix.gdpr.anonymize', []);

        foreach ($map as $column => $replacement) {
            $user->setAttribute(
                $column,
                $replacement instanceof Closure ? $replacement($user) : $replacement,
            );
        }

        $user->save();

        // Soft-deletable models are also flagged deleted so they drop out of
        // normal queries while the anonymized row is retained.
        if (in_array(SoftDeletes::class, class_uses_recursive($user), true)) {
            $user->delete();
        }
    }
}
