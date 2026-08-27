<?php

declare(strict_types=1);

namespace Happones\Kinetix\Credentials;

use Happones\Kinetix\Support\DialCodes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Who a login string refers to, when "email" is not the only answer.
 *
 * Plenty of businesses employ people with no email address — but almost all of
 * them have a phone, and a username can always be assigned. The starter kit
 * assumes email everywhere, so this resolves whichever identifiers you accept
 * back to a user, consistently, in one place.
 *
 * Two rules hold the whole thing together:
 *
 *  1. **A login is classified before it is queried.** An input that looks like
 *     an email is only ever matched against `email`. Without that, a user could
 *     register someone else's email address as their USERNAME and be found by
 *     it — the "I logged in as the wrong person" bug.
 *  2. **Every value is normalized the same way going in and coming out.**
 *     `+52 55 1234 5678` and `525512345678` are one phone number; stored
 *     inconsistently they defeat the unique index that is supposed to keep them
 *     from being two accounts.
 *
 * Default `fields` is `['email']`, which is exactly today's behavior.
 */
class IdentityResolver
{
    /**
     * The columns a login may be matched against, in priority order.
     *
     * @return array<int, string>
     */
    public function fields(): array
    {
        /** @var array<int, string> $fields */
        $fields = (array) config('kinetix.credentials.identity.fields', ['email']);

        $allowed = array_values(array_intersect(['email', 'username', 'phone'], $fields));

        return $allowed === [] ? ['email'] : $allowed;
    }

    public function accepts(string $field): bool
    {
        return in_array($field, $this->fields(), true);
    }

    /**
     * Whether anything beyond plain email login is configured.
     */
    public function enabled(): bool
    {
        return (bool) config('kinetix.credentials.enabled', false)
            && $this->fields() !== ['email'];
    }

    // -----------------------------------------------------------------
    // Normalization
    // -----------------------------------------------------------------

    /**
     * The canonical stored form of a value for a field. Apply it on the way IN
     * as well — a unique index only protects you from duplicates it can see.
     */
    public function normalize(string $field, ?string $value): string
    {
        $value = trim((string) $value);

        return match ($field) {
            'email'    => Str::lower($value),
            'username' => Str::lower($value),
            'phone'    => $this->normalizePhone($value),
            default    => $value,
        };
    }

    /**
     * A phone number as E.164: a leading `+` and digits only.
     *
     * A number typed without a country code is assumed to be in
     * `identity.phone_country` — the common case, since staff enter local
     * numbers. A leading `00` is the other way people write an international
     * prefix.
     */
    public function normalizePhone(?string $value): string
    {
        $raw = trim((string) $value);

        if ($raw === '') {
            return '';
        }

        $international = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        $digits        = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return '';
        }

        if ($international) {
            // `00` is the same intent as `+`, without the character.
            if (! str_starts_with($raw, '+') && str_starts_with($digits, '00')) {
                $digits = substr($digits, 2);
            }

            return '+'.$digits;
        }

        $country = (string) config('kinetix.credentials.identity.phone_country', '');
        $dial    = $country === '' ? null : DialCodes::for($country);

        // With no default country there is nothing to prepend — keep the digits
        // as given rather than inventing a country the number isn't from.
        return $dial === null ? '+'.$digits : '+'.$dial.$digits;
    }

    /**
     * The stored values a typed phone number could correspond to.
     *
     * Storage is strict and predictable: without a `+` or `00`, digits are a
     * LOCAL number and get the configured country's code. But a person signing
     * in types their number however they know it, and a bare string that
     * already begins with the country code is genuinely ambiguous — `52 55
     * 1234 5678` could be a Mexican number written in full, or a local number
     * that happens to start with 52.
     *
     * Nothing can settle that without a real numbering-plan library, so the
     * ambiguity is confined to LOOKUP, where it is harmless: both readings are
     * matched against values that were stored canonically, and at most one of
     * them exists.
     *
     * @return array<int, string>
     */
    public function phoneLookupCandidates(?string $value): array
    {
        $strict = $this->normalizePhone($value);

        if ($strict === '') {
            return [];
        }

        $raw = trim((string) $value);

        if (str_starts_with($raw, '+') || str_starts_with($raw, '00')) {
            return [$strict];
        }

        $digits  = preg_replace('/\D+/', '', $raw) ?? '';
        $country = (string) config('kinetix.credentials.identity.phone_country', '');
        $dial    = $country === '' ? null : DialCodes::for($country);

        if ($dial === null || ! str_starts_with($digits, $dial)) {
            return [$strict];
        }

        // Read as already carrying the country code — but only if what remains
        // is still long enough to be a number.
        $remainder = substr($digits, strlen($dial));

        return strlen($remainder) >= 7
            ? array_values(array_unique([$strict, '+'.$digits]))
            : [$strict];
    }

    // -----------------------------------------------------------------
    // Classification
    // -----------------------------------------------------------------

    /**
     * Which accepted fields a login string could possibly be, in priority
     * order. Usually exactly one — the point is that it is never "all of them".
     *
     * @return array<int, string>
     */
    public function classify(?string $login): array
    {
        $login = trim((string) $login);

        if ($login === '') {
            return [];
        }

        // An email is unmistakable, and must never fall through to the other
        // fields: that is the cross-match this method exists to prevent.
        if (filter_var($login, FILTER_VALIDATE_EMAIL) !== false) {
            return $this->accepts('email') ? ['email'] : [];
        }

        $candidates = [];

        if ($this->accepts('phone') && $this->looksLikePhone($login)) {
            $candidates[] = 'phone';
        }

        if ($this->accepts('username') && $this->looksLikeUsername($login)) {
            $candidates[] = 'username';
        }

        return $candidates;
    }

    /**
     * Digits, with the punctuation people put between them, and enough of them
     * to be a real number.
     */
    public function looksLikePhone(string $value): bool
    {
        if (preg_match('/^\+?[\d\s().\-]+$/', $value) !== 1) {
            return false;
        }

        return strlen((string) preg_replace('/\D+/', '', $value)) >= 7;
    }

    public function looksLikeUsername(string $value): bool
    {
        return preg_match($this->usernamePattern(), $value) === 1;
    }

    /**
     * The shape a username may take.
     *
     * The default deliberately excludes `@`, so a username can never be
     * mistaken for — or registered as — somebody's email address.
     */
    public function usernamePattern(): string
    {
        return (string) config(
            'kinetix.credentials.identity.username_pattern',
            '/^[a-zA-Z0-9._-]{3,32}$/',
        );
    }

    // -----------------------------------------------------------------
    // Resolution
    // -----------------------------------------------------------------

    /**
     * The user a login string refers to, or null.
     *
     * Only the classified fields are queried, each against its normalized
     * value. If more than one user comes back — which means the data violates
     * the uniqueness the fields are supposed to have — nobody is resolved: an
     * ambiguous identity is worse than a failed login.
     */
    public function resolve(?string $login): ?Model
    {
        $candidates = $this->classify($login);

        if ($candidates === []) {
            return null;
        }

        $model = $this->userModel();

        if ($model === null) {
            return null;
        }

        $matches = $model::query()
            ->where(function (Builder $query) use ($candidates, $login): void {
                foreach ($candidates as $field) {
                    if ($field === 'phone') {
                        $query->orWhereIn($field, $this->phoneLookupCandidates($login));

                        continue;
                    }

                    $query->orWhere($field, $this->normalize($field, $login));
                }
            })
            ->limit(2)
            ->get();

        if ($matches->count() > 1) {
            Log::warning('Kinetix: a login matched more than one user — the identity columns are not unique.', [
                'fields' => $candidates,
            ]);

            return null;
        }

        return $matches->first();
    }

    /**
     * Resolve a login and verify the password — the whole of what an
     * `authenticateUsing()` callback needs.
     *
     * Returns null for every kind of failure, on purpose: a message that
     * distinguishes "no such user" from "wrong password" turns the login form
     * into a directory anyone can enumerate.
     */
    public function attempt(?string $login, ?string $password): ?Model
    {
        $user = $this->resolve($login);

        if ($user === null) {
            // Spend the same time as a real check would. Without this, an
            // unknown identifier answers measurably faster than a known one —
            // which leaks exactly what the generic message is hiding.
            Hash::check((string) $password, $this->timingHash());

            return null;
        }

        if (! Hash::check((string) $password, (string) $user->getAttribute('password'))) {
            return null;
        }

        // A temporary credential nobody used within its TTL is dead; an admin
        // has to issue a new one (see the Credentials password policy).
        if (app(PasswordPolicy::class)->temporaryHasExpired($user)) {
            return null;
        }

        return $user;
    }

    // -----------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------

    /**
     * Validation rules for the accepted identifiers, for a create/update form.
     *
     * Each field is nullable and unique, plus a guard that at least ONE of them
     * is present — a user nobody can identify is not a user.
     *
     * @param  Model|int|string|null            $ignore the record being updated
     * @return array<string, array<int, mixed>>
     */
    public function rules(mixed $ignore = null): array
    {
        $model  = $this->userModel();
        $table  = $model === null ? 'users' : (new $model)->getTable();
        $fields = $this->fields();
        $rules  = [];

        foreach ($fields as $field) {
            $unique = Rule::unique($table, $field);

            if ($ignore !== null) {
                $unique = $unique->ignore($ignore instanceof Model ? $ignore->getKey() : $ignore);
            }

            $rules[$field] = match ($field) {
                'email'    => ['nullable', 'email', 'max:255', $unique],
                'username' => ['nullable', 'string', 'max:32', 'regex:'.$this->usernamePattern(), $unique],
                'phone'    => ['nullable', 'string', 'max:20', $unique],
                default    => ['nullable', 'string', $unique],
            };
        }

        // With several accepted fields, each one alone is optional — but the
        // set as a whole is not.
        if (count($fields) > 1) {
            foreach ($fields as $field) {
                $others          = array_values(array_diff($fields, [$field]));
                $rules[$field][] = 'required_without_all:'.implode(',', $others);
            }
        } else {
            $rules[$fields[0]][0] = 'required';
        }

        return $rules;
    }

    /**
     * @return class-string<Model>|null
     */
    public function userModel(): ?string
    {
        /** @var class-string<Model>|null $model */
        $model = config('kinetix.credentials.user_model')
            ?: config('kinetix.membership.user_model', 'App\\Models\\User');

        return is_string($model) && class_exists($model) ? $model : null;
    }

    /**
     * A throwaway hash to compare against when no user was found, so a failed
     * lookup costs the same as a failed password.
     */
    protected function timingHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(32));
    }
}
