<?php

declare(strict_types=1);

namespace Happones\Kinetix\Membership\Events;

use Happones\Kinetix\Membership\MemberProvision;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A member was revoked (once: revoking again is a no-op). `actor` is null
 * when it was done from code.
 *
 * Dispatched after commit. This is the place to clean up what `apply()` wrote.
 */
class MemberRevoked implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly MemberProvision $provision,
        public readonly ?Authenticatable $actor = null,
    ) {}
}
