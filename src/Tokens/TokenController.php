<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tokens;

use Closure;
use Happones\Kinetix\Data\TokenData;
use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;

/**
 * Self-service personal access tokens. Each authenticated user manages only
 * their own tokens (no admin ability). Requires the User model to use
 * Laravel\Sanctum\HasApiTokens; the plaintext token is returned exactly once
 * on creation and never persisted in a readable form.
 */
class TokenController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->tokenableUser($request);

        return response()->json([
            'tokens' => $this->teamTokens($user->tokens())
                ->latest()
                ->get()
                ->filter($this->visibleToCurrentTeam(...))
                ->map(static fn (Model $token): TokenData => TokenData::fromModel($token))
                ->values(),
            'scopes'        => app(TokenScopeRegistry::class)->all(),
            'defaultScopes' => app(TokenScopeRegistry::class)->defaultKeys(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->tokenableUser($request);

        $allowed = app(TokenScopeRegistry::class)->keys();

        $rules = [
            'name'        => ['required', 'string', 'max:255'],
            'abilities'   => ['array'],
            'abilities.*' => ['string'],
            'expires_at'  => ['nullable', 'date', 'after:now'],
        ];

        $rules['abilities.*'][] = static function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && KinetixTokens::isInternalAbility($value)) {
                $fail('validation.not_in')->translate(['attribute' => $attribute]);
            }
        };

        // When the host declares a scope catalog, tokens must be granted at
        // least one of those scopes. Otherwise they default to full access.
        if ($allowed !== []) {
            $rules['abilities']   = ['required', 'array', 'min:1'];
            $rules['abilities.*'] = [...$rules['abilities.*'], Rule::in($allowed)];
        }

        $validated = $request->validate($rules);

        // A token that cannot be tied to a team would answer for every team.
        abort_if($this->teamColumn() !== null && config('kinetix.teams', false) && KinetixTeams::currentTeamKey() === null, 403);

        $abilities = $this->mintedAbilities($validated['abilities'] ?? ['*']);

        // Optional expiration — Sanctum's guard rejects the token past this
        // date. A bare Y-m-d expires at the END of that day.
        $expiresAt = isset($validated['expires_at'])
            ? Date::parse($validated['expires_at'])->endOfDay()
            : null;

        $newToken = $user->createToken($validated['name'], $abilities, $expiresAt);

        if (($column = $this->teamColumn()) !== null && $newToken->accessToken->getAttribute($column) === null) {
            $newToken->accessToken->forceFill([$column => KinetixTeams::currentTeamKey()])->save();
        }

        // The plaintext token is shown exactly once.
        return response()->json([
            'token'          => TokenData::fromModel($newToken->accessToken),
            'plainTextToken' => $newToken->plainTextToken,
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $this->tokenableUser($request);

        $this->teamTokens($user->tokens())
            ->whereKey($request->route('token'))
            ->get()
            ->filter($this->visibleToCurrentTeam(...))
            ->each(static fn (Model $token): ?bool => $token->delete());

        return response()->json(['status' => 'success']);
    }

    /**
     * The tokens of the team being served, plus the teamless ones minted before
     * tokens carried a team. Without a configured column, every token: a list
     * that showed another team's tokens let them be revoked from here.
     *
     * @template TQuery of Builder|Relation
     *
     * @param  TQuery $query
     * @return TQuery
     */
    protected function teamTokens(Builder|Relation $query): Builder|Relation
    {
        $column = $this->teamColumn();

        if ($column === null) {
            return $query;
        }

        $teamKey = KinetixTeams::currentTeamKey();

        return $query->where(fn ($scope) => $scope
            ->whereNull($column)
            ->when($teamKey !== null, fn ($inner) => $inner->orWhere($column, $teamKey)));
    }

    /**
     * Whether a token belongs on the current team's list: sealed to this team
     * or not sealed at all (minted before sealing). Without a seal setting the
     * column filter in {@see teamTokens()} is the whole rule.
     */
    protected function visibleToCurrentTeam(Model $token): bool
    {
        if (KinetixTokens::sealPrefix() === null || ! config('kinetix.teams', false)) {
            return true;
        }

        $team = KinetixTokens::teamOf($token);

        if ($team === null) {
            return true;
        }

        $current = KinetixTeams::currentTeamKey();

        return $current !== null && (string) $team === (string) $current;
    }

    /**
     * The abilities a new token is minted with: the requested ones minus any
     * the form may not set (a hand-written `team:9` seal), plus the stamped
     * abilities and the current team's seal.
     *
     * @param  array<int, string> $requested
     * @return array<int, string>
     */
    protected function mintedAbilities(array $requested): array
    {
        $abilities = array_values(array_filter($requested, static fn (string $ability): bool => ! KinetixTokens::isInternalAbility($ability)));

        $prefix = KinetixTokens::sealPrefix();

        if ($prefix !== null && config('kinetix.teams', false)) {
            $teamKey = KinetixTeams::currentTeamKey();

            // A token that cannot be sealed would answer for every team.
            abort_if($teamKey === null, 403);

            $abilities[] = $prefix.$teamKey;
        }

        return array_values(array_unique([...$abilities, ...KinetixTokens::stampAbilities()]));
    }

    protected function teamColumn(): ?string
    {
        $column = config('kinetix.tokens.team_column');

        return is_string($column) && $column !== '' ? $column : null;
    }

    /**
     * Resolve the authenticated user and ensure it exposes Sanctum's token API.
     */
    protected function tokenableUser(Request $request): mixed
    {
        $user = $request->user();

        abort_if($user === null, 401);
        abort_unless(
            method_exists($user, 'createToken') && method_exists($user, 'tokens'),
            500,
            'The authenticatable model must use Laravel\Sanctum\HasApiTokens to manage developer tokens.'
        );

        return $user;
    }
}
