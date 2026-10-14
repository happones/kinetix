<?php

declare(strict_types=1);

namespace Happones\Kinetix\Impersonation\Middleware;

use Closure;
use Happones\Kinetix\Impersonation\ImpersonationManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a route while impersonating. Apply it (alias `kinetix.impersonation.protect`)
 * to sensitive routes — password / email / 2FA / billing / account deletion — so
 * an admin acting as another user can't change credentials or spend their money.
 *
 * `kinetix.impersonation.protect:writes` blocks only the requests that change
 * something, so the admin can still see the page (the plan, the invoices) and
 * help, without acting on it.
 */
class DenyWhileImpersonating
{
    public function __construct(protected ImpersonationManager $manager) {}

    public function handle(Request $request, Closure $next, ?string $only = null): Response
    {
        $guarded = $only !== 'writes' || ! $request->isMethodSafe();

        abort_if(
            $guarded && $this->manager->isImpersonating(),
            403,
            'This action is not allowed while impersonating.',
        );

        return $next($request);
    }
}
