<?php

declare(strict_types=1);

namespace Happones\Kinetix\Entitlements;

use Closure;
use Happones\Kinetix\Billing\BillingManager;
use Happones\Kinetix\Features\FeatureManager;
use Happones\Kinetix\Support\ConfigCallback;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * One declared entitlement: the four gating layers a SaaS feature actually
 * sits behind, named once and evaluated in one place.
 *
 *     KinetixEntitlements::define('alerts.discord')
 *         ->label('Discord alerts')
 *         ->flag('discord-alerts')            // rolled out here?
 *         ->plan('alerts.discord')            // did this tenant buy it?
 *         ->limit('alerts', [Alerts::class, 'countFor'])   // room left?
 *         ->permission('alerts.manage');      // may this user do it?
 *
 * Every layer is optional; a layer that isn't declared simply passes. What the
 * declaration buys you is that the composition lives in ONE place instead of
 * being re-typed — differently — at every call site, and that the answer says
 * WHICH layer refused ({@see DenialReason}) instead of collapsing to a bool.
 *
 * ## Evaluation order (fixed, and deliberately so)
 *
 * `flag → plan → limit → permission`, short-circuiting at the first denial:
 *
 *  1. **flag** first because it is the cheapest and the most absolute — an
 *     unreleased feature should look like it doesn't exist (404), not like
 *     something you were refused.
 *  2. **plan** and **limit** next: one memoized, tenant-level answer that
 *     covers everyone in the team, and the denial people can act on (upgrade).
 *  3. **permission** last: the per-user check, the one most likely to differ
 *     between two people looking at the same page.
 *
 * The order also controls COST. The permission layer is the one that can run a
 * policy — and in a table it runs per row — so it is asked only after the
 * cheap, memoized, tenant-wide layers have already had their say.
 */
class Entitlement
{
    protected ?string $label = null;

    protected ?string $flag = null;

    protected ?string $planCapability = null;

    protected ?string $planFeaturePath = null;

    protected ?string $limitKey = null;

    /** @var Closure|array{0: class-string|object, 1: string}|class-string|null */
    protected mixed $limitCount = null;

    protected ?string $permission = null;

    protected bool $shared = true;

    public function __construct(public readonly string $name) {}

    /**
     * Human label, for upsell copy and the roles/plans UIs.
     */
    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label ?? $this->name;
    }

    /**
     * Require a feature flag to be active ({@see FeatureManager}).
     */
    public function flag(string $flag): static
    {
        $this->flag = $flag;

        return $this;
    }

    /**
     * Require a plan CAPABILITY — `features.capabilities.{$capability}` on the
     * billable's current plan, the same sugar as `$billable->planAllows()`.
     */
    public function plan(string $capability): static
    {
        $this->planCapability = $capability;

        return $this;
    }

    /**
     * Require a RAW plan feature dot-path, for features JSON that doesn't
     * follow the `capabilities.*` convention (`->planFeature('legacy.sso')`).
     */
    public function planFeature(string $path): static
    {
        $this->planFeaturePath = $path;

        return $this;
    }

    /**
     * Require room under the plan's `features.usage.{$key}` limit.
     *
     * `$count` resolves the tenant's CURRENT usage and receives the billable:
     * `fn ($billable) => $billable->projects()->count()`. It runs a query, so
     * keep it a `COUNT` (or a cached counter) — it is evaluated once per
     * request per entitlement, including for the `kinetix_entitlements` share.
     * Use {@see shared()} to keep an expensive one off the share.
     *
     * @param Closure|array{0: class-string|object, 1: string}|class-string $count
     */
    public function limit(string $key, Closure|array|string $count): static
    {
        $this->limitKey   = $key;
        $this->limitCount = $count;

        return $this;
    }

    /**
     * Require a Gate ability — a Kinetix permission key (`projects.create`) or
     * any ability your app defines.
     *
     * This layer is ALWAYS evaluated when declared: authorization must never
     * silently skip, so the ability has to actually exist (registered in the
     * Kinetix registry, or defined on the Gate).
     */
    public function permission(string $ability): static
    {
        $this->permission = $ability;

        return $this;
    }

    /**
     * Whether this entitlement travels on the `kinetix_entitlements` Inertia
     * prop. Turn it off for one whose usage count is too expensive to run on
     * every page load — it is then server-only, checked where it matters.
     */
    public function shared(bool $shared = true): static
    {
        $this->shared = $shared;

        return $this;
    }

    public function isShared(): bool
    {
        return $this->shared;
    }

    /**
     * The Gate ability this entitlement requires, if any — so `kinetix:doctor`
     * can flag one that nothing defines (an ability nobody registered denies
     * for everyone, forever, in silence).
     */
    public function permissionAbility(): ?string
    {
        return $this->permission;
    }

    /**
     * Whether any layer here needs the billing module. With billing off those
     * layers are skipped, so a declaration that consists only of them gates
     * nothing — `kinetix:doctor` warns about it.
     */
    public function usesPlanLayers(): bool
    {
        return $this->planCapability  !== null
            || $this->planFeaturePath !== null
            || $this->limitKey        !== null;
    }

    /**
     * Whether any layer at all was declared. A bare entitlement always allows
     * — it is a name with no rules yet, not a locked door.
     */
    public function isGated(): bool
    {
        return $this->flag            !== null
            || $this->planCapability  !== null
            || $this->planFeaturePath !== null
            || $this->limitKey        !== null
            || $this->permission      !== null;
    }

    /**
     * Evaluate every declared layer in order, stopping at the first denial.
     *
     * `$user` overrides who the FLAG and PERMISSION layers are resolved for.
     * The plan layers always follow the request's own billable
     * ({@see BillingManager::resolve()}) — plans belong to the tenant being
     * served, not to whoever is being asked about.
     */
    public function evaluate(?Authenticatable $user = null): Verdict
    {
        $user ??= auth()->user();

        if ($this->flag !== null && ! $this->flagIsActive($user)) {
            return Verdict::deny($this->name, DenialReason::Flag);
        }

        $billable = $this->billingApplies() ? $this->billable() : null;

        if ($this->planCapability !== null || $this->planFeaturePath !== null) {
            $planVerdict = $this->evaluatePlan($billable);

            if ($planVerdict !== null) {
                return $planVerdict;
            }
        }

        $remaining = null;

        if ($this->limitKey !== null) {
            [$limitVerdict, $remaining] = $this->evaluateLimit($billable);

            if ($limitVerdict !== null) {
                return $limitVerdict;
            }
        }

        if ($this->permission !== null && ! Gate::forUser($user)->allows($this->permission)) {
            return Verdict::deny($this->name, DenialReason::Permission, $remaining);
        }

        return Verdict::allow($this->name, $remaining);
    }

    protected function flagIsActive(?Authenticatable $user): bool
    {
        try {
            // A null scope lets FeatureManager pick its own (the user, or the
            // team when `features.teams` is on); an explicit user overrides it.
            return app(FeatureManager::class)->active($this->flag, $user);
        } catch (Throwable) {
            // An undefined or throwing flag denies rather than 500s the page —
            // the same defensive contract FeatureManager applies to guests.
            return false;
        }
    }

    /**
     * Whether the plan layers should run at all. With the billing module off
     * there are no plans to consult, so a plan layer must not block the app —
     * the same fail-open rule the rest of billing follows for uninstalled
     * infrastructure.
     */
    protected function billingApplies(): bool
    {
        return (bool) config('kinetix.billing.enabled', false)
            && ($this->planCapability !== null || $this->planFeaturePath !== null || $this->limitKey !== null);
    }

    protected function billable(): ?Model
    {
        try {
            return BillingManager::resolve()->billable();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Null when the plan layer passes (or does not apply), a denial otherwise.
     */
    protected function evaluatePlan(?Model $billable): ?Verdict
    {
        if (! $this->billingApplies()) {
            return null;
        }

        // Billing IS on but no billable resolves (a guest, a misconfigured
        // resolver): capabilities fail CLOSED, matching `planAllows()`.
        if ($billable === null || ! method_exists($billable, 'canUseFeature')) {
            return Verdict::deny($this->name, DenialReason::Plan);
        }

        $path = $this->planFeaturePath ?? 'capabilities.'.$this->planCapability;

        return $billable->canUseFeature($path)
            ? null
            : Verdict::deny($this->name, DenialReason::Plan);
    }

    /**
     * @return array{0: ?Verdict, 1: ?int} the denial (or null) and the units left
     */
    protected function evaluateLimit(?Model $billable): array
    {
        if (! $this->billingApplies()) {
            return [null, null];
        }

        // Unlike a capability, a limit fails OPEN with no billable: blocking
        // creation is never something an app opts into by accident.
        if ($billable === null
            || ! method_exists($billable, 'isWithinPlanLimit')
            || ! method_exists($billable, 'remainingPlanLimit')) {
            return [null, null];
        }

        $count     = $this->resolveCount($billable);
        $remaining = $billable->remainingPlanLimit('usage.'.$this->limitKey, $count);

        return $billable->isWithinPlanLimit($this->limitKey, $count)
            ? [null, $remaining]
            : [Verdict::deny($this->name, DenialReason::Limit, $remaining), $remaining];
    }

    protected function resolveCount(Model $billable): int
    {
        $callback = ConfigCallback::resolve($this->limitCount);

        return $callback === null ? 0 : (int) $callback($billable);
    }
}
