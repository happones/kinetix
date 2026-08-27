<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing;

use Symfony\Component\HttpFoundation\Response;

/**
 * The single answer to "the plan doesn't include this".
 *
 * A denied WEB request redirects to the configured upgrade page
 * (`kinetix.billing.upgrade_url`) carrying the message as a flash + toast —
 * the upsell pattern, which sells instead of scolding. JSON requests, and web
 * requests in an app with no upgrade page configured, get a plain 403 with the
 * same message.
 *
 * Shared by `kinetix.plan` ({@see Middleware\EnsurePlanCapability}) and by
 * every plan/limit denial coming out of the entitlements layer, so a user
 * never sees two different behaviors for the same refusal.
 */
final class UpsellResponse
{
    /**
     * @return Response the redirect, when one applies; otherwise this aborts
     */
    public static function make(string $message): Response
    {
        $upgradeUrl = config('kinetix.billing.upgrade_url');

        if (! request()->expectsJson() && is_string($upgradeUrl) && $upgradeUrl !== '') {
            return redirect($upgradeUrl)
                ->with('message', $message)
                ->with('kinetix_toast', $message);
        }

        abort(403, $message);
    }
}
