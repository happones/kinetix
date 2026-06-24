<?php

declare(strict_types=1);

namespace Happones\Kinetix\Membership;

/**
 * Lifecycle state of a provisioned member.
 *
 * - `Pending`  — admin provisioned the email; the person has not activated yet.
 * - `Active`   — the person set a password and the account exists.
 * - `Revoked`  — an admin removed the member from the team.
 */
enum MemberProvisionStatus: string
{
    case Pending = 'pending';
    case Active  = 'active';
    case Revoked = 'revoked';
}
