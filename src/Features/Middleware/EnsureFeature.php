<?php

declare(strict_types=1);

namespace Happones\Kinetix\Features\Middleware;

use Closure;
use Happones\Kinetix\Features\FeatureManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route behind a feature flag (alias `kinetix.feature`):
 *
 *     Route::get('/beta', ...)->middleware('kinetix.feature:beta-search');
 *
 * Aborts 404 (the route effectively doesn't exist for users without the flag)
 * when the feature is inactive. Works with either driver.
 */
class EnsureFeature
{
    public function __construct(protected FeatureManager $features) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless($this->features->active($feature), 404);

        return $next($request);
    }
}
