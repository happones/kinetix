<?php

declare(strict_types=1);

namespace Happones\Kinetix\Entitlements;

use Happones\Kinetix\Support\Memo;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;

/**
 * The declared entitlements, and the one place they are evaluated.
 *
 * Declarations are CODE (a service provider), like the permission registry and
 * the feature-flag definitions — nothing is read from the database at request
 * time, so `config:cache` / `route:cache` / Octane are all safe.
 *
 * Verdicts are memoized per (user × entitlement) for the request, so asking
 * the same question from the controller, from a table's actions and from the
 * Inertia share costs one evaluation, not three.
 */
class EntitlementRegistry
{
    /** The {@see Memo} store holding this request's verdicts. */
    public const MEMO = 'entitlements.verdict';

    /** @var array<string, Entitlement> */
    protected array $entitlements = [];

    /** @var array<string, true> Names already warned about, so a hot path logs once. */
    protected array $warned = [];

    /**
     * Declare an entitlement (or continue configuring an existing one, so two
     * providers can contribute layers to the same name).
     */
    public function define(string $name): Entitlement
    {
        return $this->entitlements[$name] ??= new Entitlement($name);
    }

    public function get(string $name): ?Entitlement
    {
        return $this->entitlements[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->entitlements[$name]);
    }

    /**
     * @return array<string, Entitlement>
     */
    public function all(): array
    {
        return $this->entitlements;
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->entitlements);
    }

    /**
     * Evaluate one entitlement for a user (the authenticated one by default).
     *
     * An UNDECLARED name is denied — authorization fails closed — and warns
     * while debugging, because the overwhelmingly likely cause is a typo or a
     * provider that never ran, and a silent `true` there would be a hole.
     */
    public function check(string $name, ?Authenticatable $user = null): Verdict
    {
        $entitlement = $this->get($name);

        if ($entitlement === null) {
            $this->warnUndefined($name);

            return Verdict::deny($name, DenialReason::Undefined);
        }

        $subject = $user ?? auth()->user();

        // Guests have no object to key a memo on — and no plan or permissions
        // either, so the evaluation is cheap anyway.
        if (! is_object($subject)) {
            return $entitlement->evaluate($user);
        }

        /** @var Verdict $verdict */
        $verdict = Memo::remember(
            self::MEMO,
            $subject,
            $name,
            static fn (): Verdict => $entitlement->evaluate($subject),
        );

        return $verdict;
    }

    public function allows(string $name, ?Authenticatable $user = null): bool
    {
        return $this->check($name, $user)->allowed;
    }

    public function denies(string $name, ?Authenticatable $user = null): bool
    {
        return $this->check($name, $user)->denied();
    }

    /**
     * Every entitlement that opted into the Inertia share, resolved for the
     * user.
     *
     * @return array<string, array{allowed: bool, reason: ?string, remaining: ?int}>
     */
    public function resolveShared(?Authenticatable $user = null): array
    {
        $resolved = [];

        foreach ($this->entitlements as $name => $entitlement) {
            if (! $entitlement->isShared()) {
                continue;
            }

            $resolved[$name] = $this->check($name, $user)->toArray();
        }

        return $resolved;
    }

    /**
     * Drop this request's memoized verdicts. For workers and tests that change
     * a role, a plan or a flag mid-process.
     */
    public function flush(): void
    {
        Memo::flush(self::MEMO);
    }

    /**
     * Forget every declaration too — tests that redeclare entitlements.
     */
    public function reset(): void
    {
        $this->entitlements = [];
        $this->warned       = [];
        $this->flush();
    }

    protected function warnUndefined(string $name): void
    {
        if (isset($this->warned[$name]) || ! config('app.debug', false)) {
            return;
        }

        $this->warned[$name] = true;

        Log::warning(
            "Kinetix: entitlement '{$name}' is not declared, so it is DENIED. "
            .'Declare it with KinetixEntitlements::define() in a service provider, or fix the name.'
        );
    }
}
