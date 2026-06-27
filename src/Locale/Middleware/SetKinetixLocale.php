<?php

declare(strict_types=1);

namespace Happones\Kinetix\Locale\Middleware;

use Closure;
use Happones\Kinetix\Locale\LocaleManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Apply the user's persisted locale to the application for this request (alias
 * `kinetix.locale`). Add it to your web middleware group so every page renders
 * in the selected language:
 *
 *     // bootstrap/app.php
 *     $middleware->web(append: [\Happones\Kinetix\Locale\Middleware\SetKinetixLocale::class]);
 *
 * No-ops when the feature is disabled or no locale has been chosen.
 */
class SetKinetixLocale
{
    public function __construct(protected LocaleManager $manager) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (config('kinetix.locale.enabled', false)) {
            $user = $request->user();
            $this->manager->apply($user instanceof Model ? $user : null);
        }

        return $next($request);
    }
}
