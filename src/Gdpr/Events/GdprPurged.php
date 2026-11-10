<?php

declare(strict_types=1);

namespace Happones\Kinetix\Gdpr\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user's account was purged (anonymized, deleted, or handled by your own
 * deletion callback). Modules that keep rows about a person listen to it and
 * remove what they hold.
 */
class GdprPurged
{
    use Dispatchable;

    public function __construct(public readonly Model $user) {}
}
