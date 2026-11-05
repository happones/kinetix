<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * The binding every signed endpoint descriptor carries.
 *
 * A descriptor is the encrypted payload a page hands the browser so a Kinetix
 * endpoint can act without the client ever naming a class, a column or a
 * record. Encryption only proves Kinetix minted it — not that whoever presents
 * it may use it — so every descriptor also records:
 *
 *  - the USER it was minted for: a token lifted from someone else's page (with
 *    a wider allowlist) is refused;
 *  - the TEAM it was minted in (when `kinetix.teams` is on): a token minted in
 *    one team can't be replayed against another team's endpoints, where the
 *    same user may hold a wider role;
 *  - an EXPIRY (`kinetix.tables.token_ttl` minutes): bounding the replay window
 *    of a token captured from a long-lived page;
 *  - the AUDIENCE it was minted for ({@see DescriptorAudience}): a token minted
 *    for one endpoint family is refused by every other, however similar its
 *    payload looks.
 *
 * The team claim is checked against the team the request is ROUTED to, so it
 * applies to every `{current_team}`-prefixed endpoint; the few endpoints that
 * are deliberately not team-prefixed (exports) still check user and expiry.
 * A payload missing any claim predates them and is refused as expired, so the
 * page just reloads.
 *
 * @internal
 */
final class SignedDescriptor
{
    /**
     * Encrypt a payload together with its binding claims.
     *
     * @param array<string, mixed> $payload
     */
    public static function seal(array $payload, DescriptorAudience $audience): string
    {
        return Crypt::encrypt(self::bind($payload, $audience));
    }

    /**
     * Add the binding claims to a payload, for callers that encrypt it
     * themselves.
     *
     * @param  array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function bind(array $payload, DescriptorAudience $audience): array
    {
        $ttl = config('kinetix.tables.token_ttl', 1440);

        return ['aud' => $audience->value] + $payload + [
            'user'    => auth()->id(),
            'team'    => self::team(request()),
            'expires' => is_numeric($ttl) && (int) $ttl > 0
                ? now()->getTimestamp() + ((int) $ttl * 60)
                : null,
        ];
    }

    /**
     * Decrypt a descriptor. Null when the token is unreadable or not a payload.
     *
     * @return array<string, mixed>|null
     */
    public static function open(string $token): ?array
    {
        try {
            $payload = Crypt::decrypt($token);
        } catch (Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * Why this request may not use the payload — null when it may.
     *
     * A payload minted before descriptors carried an audience is refused as
     * expired (the page reloads); one minted for an audience outside
     * `$accepted` is foreign.
     *
     * @param array<string, mixed> $payload
     */
    public static function rejection(array $payload, Request $request, DescriptorAudience ...$accepted): ?DescriptorRejection
    {
        if (! array_key_exists('aud', $payload) || ! array_key_exists('user', $payload) || ! array_key_exists('team', $payload) || ! array_key_exists('expires', $payload)) {
            return DescriptorRejection::Expired;
        }

        $audiences = array_map(static fn (DescriptorAudience $audience): string => $audience->value, $accepted);

        if (! in_array($payload['aud'], $audiences, true)) {
            return DescriptorRejection::ForeignAudience;
        }

        $mintedFor = $payload['user'];

        // Outside a routed HTTP request the request has no user resolver — the
        // auth guard is the source of truth (same fallback as KinetixTeams).
        $user = $request->user() ?? auth()->user();

        if ($mintedFor !== null && (string) $mintedFor !== (string) $user?->getAuthIdentifier()) {
            return DescriptorRejection::ForeignUser;
        }

        if (self::isTeamRouted($request) && $payload['team'] !== self::team($request)) {
            return DescriptorRejection::ForeignTeam;
        }

        $expiresAt = $payload['expires'];

        if (is_int($expiresAt) && $expiresAt < now()->getTimestamp()) {
            return DescriptorRejection::Expired;
        }

        return null;
    }

    /**
     * Resolve the class a class token (`Exporter::token()`, `Importer::token()`)
     * names: null unless the token is readable, usable by this request and
     * names a subclass of `$base`.
     *
     * @template T of object
     *
     * @param  class-string<T>      $base
     * @return class-string<T>|null
     */
    public static function classFrom(string $token, string $base, ?Request $request = null): ?string
    {
        $payload = self::open($token);

        if ($payload === null || self::rejection($payload, $request ?? request(), DescriptorAudience::ClassToken) !== null) {
            return null;
        }

        $class = $payload['class'] ?? null;

        return is_string($class) && class_exists($class) && is_subclass_of($class, $base) ? $class : null;
    }

    /**
     * The team a descriptor is bound to: the team's ROUTE key
     * ({@see KinetixTeams::currentRouteKey()}) — exactly the segment the
     * frontend calls Kinetix endpoints under. Membership in that team is the
     * endpoint's own concern (`kinetix.permissions.team`, `keyFor()`); this
     * claim only pins the token to the team it was minted in.
     */
    private static function team(Request $request): ?string
    {
        $team = KinetixTeams::currentRouteKey($request);

        return $team === null ? null : (string) $team;
    }

    private static function isTeamRouted(Request $request): bool
    {
        return config('kinetix.teams', false)
            && ($request->route('current_team') ?? $request->route('team')) !== null;
    }
}
