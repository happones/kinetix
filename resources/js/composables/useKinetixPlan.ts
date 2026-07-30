import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import type { KinetixBillingState, KinetixSharedProps } from '@/types/kinetix';

/**
 * Resolve a dot-path inside the plan's nested features JSON, mirroring
 * Laravel's `data_get()` for plain objects (no wildcards).
 */
function dataGet(
    source: Record<string, unknown> | undefined,
    path: string,
    fallback: unknown = undefined,
): unknown {
    let current: unknown = source;

    for (const segment of path.split('.')) {
        if (
            current === null ||
            typeof current !== 'object' ||
            !(segment in (current as Record<string, unknown>))
        ) {
            return fallback;
        }

        current = (current as Record<string, unknown>)[segment];
    }

    return current;
}

/**
 * Frontend mirror of the backend plan-gating helpers (`HasPlan` /
 * `Plan::canUseFeature()` / `hasReachedLimit()` / `remainingLimit()`). Reads
 * the `kinetix_billing` shared prop — the billable's CURRENT plan with its
 * features JSON — so menus, buttons and CTAs gate on exactly the same
 * dot-paths the server enforces (`plan.feature` middleware, `HasPlan`).
 * Reactive: plan swaps propagate on the next Inertia visit.
 *
 *     const { canUseFeature, hasReachedLimit, remaining } = useKinetixPlan();
 *
 *     canUseFeature('capabilities.api');          // show the API menu?
 *     hasReachedLimit('usage.products', count);   // disable "Add product"?
 *     remaining('usage.products', count);         // "3 left on your plan"
 *
 * IMPORTANT: this is display gating only — the server must still enforce the
 * feature (middleware / HasPlan checks) on every write.
 */
export function useKinetixPlan() {
    const page = usePage<KinetixSharedProps>();

    const state: ComputedRef<KinetixBillingState> = computed(
        () => page.props.kinetix_billing ?? { enabled: false, plan: null },
    );

    /** The current plan (slug, name, features) or null when unresolved. */
    const plan = computed(() => state.value.plan ?? null);

    /** Whether the billing module is on (the shared prop is being populated). */
    const enabled = computed(() => state.value.enabled === true);

    const onPlan = (slug: string): boolean => plan.value?.slug === slug;

    /** Raw feature value at a dot-path (e.g. 'usage.products'), or fallback. */
    const featureValue = (path: string, fallback: unknown = null): unknown =>
        dataGet(plan.value?.features, path, fallback);

    /**
     * Whether the plan grants a feature — same semantics as the backend:
     * booleans as-is, arrays when non-empty, everything else by truthiness
     * (0 / null / missing / no plan = denied).
     */
    const canUseFeature = (path: string): boolean => {
        const value = dataGet(plan.value?.features, path);

        if (typeof value === 'boolean') {
            return value;
        }

        if (Array.isArray(value)) {
            return value.length > 0;
        }

        return Boolean(value);
    };

    /**
     * Whether a usage count has reached the plan's limit at the dot-path.
     * A null/missing limit — or no plan at all — means unlimited (false).
     */
    const hasReachedLimit = (path: string, count: number): boolean => {
        const limit = dataGet(plan.value?.features, path);

        if (limit === null || limit === undefined) {
            return false;
        }

        return count >= Number(limit);
    };

    /**
     * Units left before the limit at the dot-path is reached, floored at zero.
     * Null means unlimited (including when no plan resolves).
     */
    const remaining = (path: string, count: number): number | null => {
        const limit = dataGet(plan.value?.features, path);

        if (limit === null || limit === undefined) {
            return null;
        }

        return Math.max(0, Number(limit) - count);
    };

    return {
        enabled,
        plan,
        onPlan,
        featureValue,
        canUseFeature,
        hasReachedLimit,
        remaining,
    };
}
