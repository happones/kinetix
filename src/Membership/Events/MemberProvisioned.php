<?php

declare(strict_types=1);

namespace Happones\Kinetix\Membership\Events;

use Happones\Kinetix\Membership\MemberProvision;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An admin provisioned a member (a new or a repeated invitation). In `direct`
 * mode the account exists by now and `MemberActivated` follows.
 *
 * Dispatched after commit: a listener never sees a provisioning that rolled back.
 */
class MemberProvisioned implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $fields the custom member fields as validated; empty when none are configured
     */
    public function __construct(
        public readonly MemberProvision $provision,
        public readonly ?Authenticatable $actor,
        public readonly array $fields = [],
    ) {}
}
