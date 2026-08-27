<?php

declare(strict_types=1);

namespace Happones\Kinetix\Credentials\Rules;

use Closure;
use Happones\Kinetix\Credentials\PasswordPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * "You can't reuse one of your last N passwords."
 *
 * Add it wherever a password is set — including Fortify's own actions, which
 * is the point: the rule takes the user explicitly, so it works in
 * `UpdateUserPassword`, `ResetUserPassword` and any screen of your own.
 *
 *     use Happones\Kinetix\Credentials\Rules\NotAPreviousPassword;
 *
 *     Validator::make($input, [
 *         'password' => ['required', 'confirmed', Password::defaults(), new NotAPreviousPassword($user)],
 *     ])->validate();
 *
 * Inert when the module is off or `passwords.history` is 0, so it is safe to
 * leave wired while the policy is disabled.
 */
class NotAPreviousPassword implements ValidationRule
{
    public function __construct(protected ?Model $user) {}

    /**
     * @param Closure(string, string|null=): PotentiallyTranslatedString $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->user === null || ! is_string($value) || $value === '') {
            return;
        }

        $policy = app(PasswordPolicy::class);

        if ($policy->wasUsedBefore($this->user, $value)) {
            $fail(__('kinetix.password_previously_used', ['count' => $policy->historyDepth()]));
        }
    }
}
