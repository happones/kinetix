<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tokens;

use Happones\Kinetix\Data\TokenData;
use Illuminate\Database\Eloquent\Model;
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
            'tokens' => $user->tokens()
                ->latest()
                ->get()
                ->map(static fn (Model $token): TokenData => TokenData::fromModel($token))
                ->values(),
            'scopes' => app(TokenScopeRegistry::class)->all(),
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

        // When the host declares a scope catalog, tokens must be granted at
        // least one of those scopes. Otherwise they default to full access.
        if ($allowed !== []) {
            $rules['abilities']   = ['required', 'array', 'min:1'];
            $rules['abilities.*'] = ['string', Rule::in($allowed)];
        }

        $validated = $request->validate($rules);

        $abilities = $validated['abilities'] ?? ['*'];

        // Optional expiration — Sanctum's guard rejects the token past this
        // date. A bare Y-m-d expires at the END of that day.
        $expiresAt = isset($validated['expires_at'])
            ? Date::parse($validated['expires_at'])->endOfDay()
            : null;

        $newToken = $user->createToken($validated['name'], $abilities, $expiresAt);

        // The plaintext token is shown exactly once.
        return response()->json([
            'token'          => TokenData::fromModel($newToken->accessToken),
            'plainTextToken' => $newToken->plainTextToken,
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $this->tokenableUser($request);

        $user->tokens()->whereKey($request->route('token'))->delete();

        return response()->json(['status' => 'success']);
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
