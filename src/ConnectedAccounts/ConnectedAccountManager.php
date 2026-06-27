<?php

declare(strict_types=1);

namespace Happones\Kinetix\ConnectedAccounts;

use Happones\Kinetix\Data\ConnectedAccountData;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Links, lists and unlinks a user's OAuth identities, and answers the
 * "does this user have a password?" question that drives the social-login UX
 * (a social-only user has no usable password until they set one).
 */
class ConnectedAccountManager
{
    /**
     * The current user's linked accounts, newest first.
     *
     * @return array<int, ConnectedAccountData>
     */
    public function for(Model $user): array
    {
        return ConnectedAccount::query()
            ->where('user_id', $user->getKey())
            ->latest()
            ->get()
            ->map(static fn (ConnectedAccount $account): ConnectedAccountData => ConnectedAccountData::fromModel($account))
            ->all();
    }

    /**
     * Link (or refresh) a provider identity to the given user.
     *
     * @throws AccountAlreadyLinkedException when the identity belongs to another user
     */
    public function link(Model $user, string $provider, object $socialUser): ConnectedAccount
    {
        $providerId = (string) $socialUser->getId();

        $existing = ConnectedAccount::query()
            ->where('provider', $provider)
            ->where('provider_id', $providerId)
            ->first();

        if ($existing !== null && (string) $existing->user_id !== (string) $user->getKey()) {
            throw new AccountAlreadyLinkedException;
        }

        $expiresIn = $socialUser->expiresIn ?? null;

        return ConnectedAccount::updateOrCreate(
            ['provider' => $provider, 'provider_id' => $providerId],
            [
                'user_id'       => $user->getKey(),
                'nickname'      => $socialUser->getNickname(),
                'name'          => $socialUser->getName(),
                'email'         => $socialUser->getEmail(),
                'avatar'        => $socialUser->getAvatar(),
                'token'         => $socialUser->token        ?? null,
                'refresh_token' => $socialUser->refreshToken ?? null,
                'expires_at'    => is_int($expiresIn) && $expiresIn > 0 ? now()->addSeconds($expiresIn) : null,
            ],
        );
    }

    /**
     * Unlink one of the user's accounts. Blocks removing the last sign-in method
     * when the user has no password (prevents account lockout).
     */
    public function unlink(Model $user, int|string $id): void
    {
        $account = ConnectedAccount::query()
            ->where('user_id', $user->getKey())
            ->whereKey($id)
            ->first();

        if ($account === null) {
            return;
        }

        abort_if($this->wouldLockOut($user), 422, __('kinetix.connected_account_last_method'));

        $account->delete();
    }

    /**
     * Whether the user currently has a usable password set.
     */
    public function hasPassword(Model $user): bool
    {
        $password = $user instanceof Authenticatable
            ? $user->getAuthPassword()
            : $user->getAttribute('password');

        return filled($password);
    }

    public function count(Model $user): int
    {
        return ConnectedAccount::query()->where('user_id', $user->getKey())->count();
    }

    /**
     * Resolve the existing app user for a social identity (login flow). Honors a
     * custom resolver; otherwise matches on email.
     */
    public function resolveLoginUser(string $provider, object $socialUser): ?Authenticatable
    {
        if (($resolver = KinetixConnectedAccounts::userResolver()) !== null) {
            return $resolver($socialUser, $provider);
        }

        $email = $socialUser->getEmail();

        if ($email === null) {
            return null;
        }

        $model = $this->userModel();

        /** @var Authenticatable|null $user */
        $user = $model::query()->where('email', $email)->first();

        return $user;
    }

    /**
     * Create a new app user for a social identity (login flow). Honors a custom
     * creator; otherwise creates a passwordless user (they may set a password
     * later). Requires the users table `password` column to be nullable.
     */
    public function createLoginUser(string $provider, object $socialUser): Authenticatable
    {
        if (($creator = KinetixConnectedAccounts::userCreator()) !== null) {
            return $creator($socialUser, $provider);
        }

        $model = $this->userModel();

        /** @var Authenticatable $user */
        $user = $model::query()->create([
            'name'     => $socialUser->getName() ?? $socialUser->getNickname() ?? Str::before((string) $socialUser->getEmail(), '@'),
            'email'    => $socialUser->getEmail(),
            'password' => null,
        ]);

        return $user;
    }

    /**
     * Set (or change) the user's password. A social-only user with no password
     * may set one freely; otherwise the current password must be supplied by the
     * caller (validated upstream).
     */
    public function setPassword(Model $user, string $password): void
    {
        $user->forceFill(['password' => Hash::make($password)])->save();
    }

    protected function wouldLockOut(Model $user): bool
    {
        if (! config('kinetix.connected_accounts.prevent_lockout', true)) {
            return false;
        }

        if ($this->hasPassword($user)) {
            return false;
        }

        return $this->count($user) <= 1;
    }

    /**
     * The configured authenticatable model class.
     *
     * @return class-string<Model>
     */
    protected function userModel(): string
    {
        $guard    = config('auth.defaults.guard', 'web');
        $provider = config("auth.guards.{$guard}.provider", 'users');

        /** @var class-string<Model> $model */
        $model = config("auth.providers.{$provider}.model", 'App\\Models\\User');

        return $model;
    }
}
