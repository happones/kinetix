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
 */
class DenyWhileImpersonating
{
    public function __construct(protected ImpersonationManager $manager) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_if(
            $this->manager->isImpersonating(),
            403,
            'This action is not allowed while impersonating.',
        );

        return $next($request);
    }
}
