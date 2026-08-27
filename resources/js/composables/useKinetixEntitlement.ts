import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import type {
    KinetixEntitlementVerdict,
    KinetixEntitlementsState,
    KinetixSharedProps,
} from '@/types/kinetix';

/** A denied entitlement always resolves to this, so callers never branch on undefined. */
const UNDECLARED: KinetixEntitlementVerdict = {
    allowed: false,
    reason: 'undefined',
    remaining: null,
};

/**
 * Frontend mirror of the backend entitlements layer. Reads the
 * `kinetix_entitlements` shared prop — every declared entitlement already
 * resolved server-side through the same `flag → plan → limit → permission`
 * evaluation the server enforces.
 *
 * The difference from `useKinetixCan` / `useKinetixPlan` / `useKinetixFeature`
 * is the REASON: a verdict says which layer refused, so the UI can hide what
 * isn't rolled out, padlock what the plan doesn't include, and simply omit
 * what this user isn't allowed to do — instead of collapsing all three into
 * one grey button.
 *
 *     const { allows, reason, remaining } = useKinetixEntitlement();
 *
 *     allows('projects.create');        // show the button?
 *     reason('projects.create');        // 'plan' | 'limit' | 'flag' | 'permission' | null
 *     remaining('projects.create');     // 3 → "3 projects left on your plan"
 *
 * Reactive: verdicts update when Inertia replaces the page props. An
 * entitlement that is not declared (or opted out of the share with
 * `->shared(false)`) resolves to denied — display gating fails closed, exactly
 * like the server.
 *
 * IMPORTANT: this is display gating only. Every mutation still needs the
 * server-side check (`kinetix.entitled` middleware or
 * `KinetixEntitlements::authorize()`).
 */
export function useKinetixEntitlement() {
    const page = usePage<KinetixSharedProps>();

    const state: ComputedRef<KinetixEntitlementsState> = computed(
        () =>
            page.props.kinetix_entitlements ?? {
                enabled: false,
                entitlements: {},
            },
    );

    /** Whether the entitlements module is on (the shared prop is populated). */
    const enabled = computed(() => state.value.enabled === true);

    const verdict = (name: string): KinetixEntitlementVerdict =>
        state.value.entitlements?.[name] ?? UNDECLARED;

    const allows = (name: string): boolean => verdict(name).allowed === true;

    const denies = (name: string): boolean => !allows(name);

    /** Which layer refused, or null when allowed. */
    const reason = (name: string): KinetixEntitlementVerdict['reason'] =>
        allows(name) ? null : verdict(name).reason;

    /** Whether the denial is answered by upgrading (plan or usage limit). */
    const isUpsell = (name: string): boolean => {
        const why = reason(name);

        return why === 'plan' || why === 'limit';
    };

    /** Units left on the entitlement's usage limit; null = unlimited or none declared. */
    const remaining = (name: string): number | null =>
        verdict(name).remaining ?? null;

    /** Every listed entitlement must allow. */
    const allowsAll = (names: string[]): boolean => names.every(allows);

    /** At least one listed entitlement must allow. */
    const allowsAny = (names: string[]): boolean => names.some(allows);

    return {
        enabled,
        verdict,
        allows,
        denies,
        reason,
        isUpsell,
        remaining,
        allowsAll,
        allowsAny,
    };
}
