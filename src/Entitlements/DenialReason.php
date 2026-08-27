<?php

declare(strict_types=1);

namespace Happones\Kinetix\Entitlements;

/**
 * WHY an entitlement was denied — the distinction a plain boolean throws away.
 *
 * "You can't do this" has three very different meanings to a user, and each
 * wants a different response: a feature still behind a flag should look like
 * it doesn't exist, a feature the tenant hasn't bought should sell itself, and
 * a feature this user isn't allowed to use should simply be refused.
 */
enum DenialReason: string
{
    /** A feature flag is off — the feature is not rolled out here. */
    case Flag = 'flag';

    /** The tenant's plan does not include the capability. */
    case Plan = 'plan';

    /** The tenant is at (or past) the plan's usage limit. */
    case Limit = 'limit';

    /** The user's role does not grant the ability. */
    case Permission = 'permission';

    /** No entitlement is declared under this name (fails closed). */
    case Undefined = 'undefined';

    /**
     * The HTTP status this denial should produce.
     *
     * A flag denial is a 404 on purpose: an unreleased feature should be
     * indistinguishable from one that was never built, so its existence can't
     * be probed. Everything else is a plain 403.
     */
    public function status(): int
    {
        return $this === self::Flag ? 404 : 403;
    }

    /**
     * Whether this denial is answered by UPGRADING — the padlock-and-CTA path
     * rather than a flat refusal.
     */
    public function isUpsell(): bool
    {
        return $this === self::Plan || $this === self::Limit;
    }

    /**
     * The translation key for the user-facing message.
     */
    public function messageKey(): string
    {
        return match ($this) {
            self::Flag, self::Undefined => 'kinetix.entitlement_unavailable',
            self::Plan, self::Limit     => 'kinetix.billing_feature_unavailable',
            self::Permission            => 'kinetix.entitlement_forbidden',
        };
    }
}
