<?php

declare(strict_types=1);

namespace Happones\Kinetix\Membership;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * What a {@see MemberFields} class is told about the call. Immutable: new
 * properties may arrive in minor versions, so read it by name and never
 * construct it yourself.
 */
final class MemberFieldsContext
{
    public function __construct(
        public readonly MemberFieldsOperation $operation,
        /** The provision's team (the request's team while provisioning). */
        public readonly int|string|null $teamId,
        /** Null on activation: the member is acting for themself. */
        public readonly ?Authenticatable $actor,
        /** Null while the provisioning form is being built. */
        public readonly ?MemberProvision $provision = null,
        /** The member's account, once it exists. */
        public readonly ?Model $user = null,
    ) {}
}
