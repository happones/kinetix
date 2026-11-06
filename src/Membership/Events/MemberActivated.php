<?php

declare(strict_types=1);

namespace Happones\Kinetix\Membership\Events;

use Happones\Kinetix\Membership\MemberProvision;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A member now has an account: they activated their invitation (`direct` is
 * false) or an admin provisioned them directly (`direct` is true).
 *
 * Dispatched after commit: a listener never sees an activation that rolled back.
 */
class MemberActivated implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $fields the custom member fields handed to `apply()`; empty when none are configured
     */
    public function __construct(
        public readonly MemberProvision $provision,
        public readonly Model $user,
        public readonly array $fields = [],
        public readonly bool $direct = false,
    ) {}
}
