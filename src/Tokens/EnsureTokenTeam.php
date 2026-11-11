<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tokens;

use Closure;
use Happones\Kinetix\Permissions\PermissionTeam;
use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Makes an API request act on the team its token belongs to, not on whichever
 * team the token holder last opened in the panel. Apply the `kinetix.token-team`
 * alias AFTER the authentication middleware:
 *
 *     Route::middleware(['auth:sanctum', 'kinetix.token-team', 'kinetix.api-log'])->group(…);
 *
 * For a request authenticated by a stored token that names a team
 * ({@see KinetixTokens::teamOf()}), the team is pinned for the rest of the
 * request (`KinetixTeams`, `ScopedToTeam` and the permission checks all see it)
 * once the user is confirmed to still belong to it (403 otherwise). A token
 * with no team passes untouched, or is refused (403) with
 * `kinetix.tokens.require_team`. Session and transient-token requests, and apps
 * with teams off, are never affected.
 *
 * Only holders who are team members belong behind this middleware: a token
 * issued to a non-member (a device, field staff) is refused with 403.
 */
class EnsureTokenTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->token($request);

        if ($token === null || ! config('kinetix.teams', false)) {
            return $next($request);
        }

        $teamKey = KinetixTokens::teamOf($token);

        if ($teamKey === null) {
            abort_if((bool) config('kinetix.tokens.require_team', false), 403);

            return $next($request);
        }

        $user = $request->user();

        abort_unless($user instanceof Model && KinetixTeams::userBelongsTo($user, $teamKey), 403);

        // A team in the URL must be the token's own: resolved BEFORE the pin
        // (membership 404 included), by whatever key the route uses.
        if ($request->route('current_team') !== null || $request->route('team') !== null) {
            $segment = $this->segmentKey($request, $user);

            abort_unless($segment !== null && (string) $segment === (string) $teamKey, 403);
        }

        $response = PermissionTeam::pin($teamKey, static fn (): Response => $next($request), $user);

        return $this->pinStreamed($response, $teamKey, $user);
    }

    /**
     * The key of the team named by the URL. The middleware runs before route
     * binding, so a host's own `Route::bind('current_team', ...)` is applied
     * here by hand (the holder must belong to what it returns, else 404);
     * otherwise {@see KinetixTeams::currentTeamKey()} resolves the segment by
     * the team's route key, membership included. A `{team:field}` binding
     * field is not read: the route key name is used.
     */
    private function segmentKey(Request $request, Model $user): int|string|null
    {
        $router = app('router');

        foreach (['current_team', 'team'] as $parameter) {
            $value  = $request->route($parameter);
            $binder = $value === null || $value instanceof Model ? null : $router->getBindingCallback($parameter);

            if ($binder === null) {
                continue;
            }

            $attribute = 'kinetix_token_team_segment_'.$parameter;
            $team      = $request->attributes->get($attribute);

            if (! $team instanceof Model) {
                $team = $binder($value, $request->route());

                // A binder that returns no model (or aborts itself) is a 404.
                abort_unless($team instanceof Model, 404);

                $request->attributes->set($attribute, $team);
            }

            abort_unless(KinetixTeams::userBelongsTo($user, $team->getKey()), 404);

            return $team->getKey();
        }

        return KinetixTeams::currentTeamKey($request);
    }

    /**
     * A streamed body runs after this middleware returned, so its callback is
     * wrapped to run under the same pin. Callbacks queued with `afterResponse`
     * and `terminate()` still run unpinned: capture the team and pass it
     * explicitly there.
     */
    private function pinStreamed(Response $response, int|string $teamKey, Model $user): Response
    {
        if (! $response instanceof StreamedResponse) {
            return $response;
        }

        $property = new ReflectionProperty(StreamedResponse::class, 'callback');
        $original = $property->getValue($response);

        if ($original instanceof Closure) {
            $response->setCallback(static function () use ($original, $teamKey, $user): void {
                PermissionTeam::pin($teamKey, $original, $user);
            });
        }

        return $response;
    }

    private function token(Request $request): ?Model
    {
        $user = $request->user();

        if ($user === null || ! method_exists($user, 'currentAccessToken')) {
            return null;
        }

        $token = $user->currentAccessToken();

        return $token instanceof Model ? $token : null;
    }
}
