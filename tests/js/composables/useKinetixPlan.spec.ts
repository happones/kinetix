import { describe, expect, it, vi } from 'vitest';

const { pageState } = vi.hoisted(() => ({
    pageState: { props: {} as Record<string, unknown> },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => pageState,
}));

import { useKinetixPlan } from '@/composables/useKinetixPlan';

const withPlan = (features: Record<string, unknown>) => {
    pageState.props = {
        kinetix_billing: {
            enabled: true,
            plan: { slug: 'pro', name: 'Pro', features },
        },
    };

    return useKinetixPlan();
};

describe('useKinetixPlan', () => {
    it('resolves feature values by dot-path with a fallback', () => {
        const { featureValue, onPlan, plan } = withPlan({
            usage: { products: 10 },
            capabilities: { api: true },
        });

        expect(plan.value?.slug).toBe('pro');
        expect(onPlan('pro')).toBe(true);
        expect(onPlan('enterprise')).toBe(false);
        expect(featureValue('usage.products')).toBe(10);
        expect(featureValue('missing.path', 'fallback')).toBe('fallback');
    });

    it('mirrors the backend truthiness rules for canUseFeature', () => {
        const { canUseFeature } = withPlan({
            capabilities: { api: true, sso: false },
            channels: ['email', 'slack'],
            empty: [],
            seats: 0,
        });

        expect(canUseFeature('capabilities.api')).toBe(true);
        expect(canUseFeature('capabilities.sso')).toBe(false);
        expect(canUseFeature('channels')).toBe(true);
        expect(canUseFeature('empty')).toBe(false);
        expect(canUseFeature('seats')).toBe(false);
        expect(canUseFeature('does.not.exist')).toBe(false);
    });

    it('treats a null or missing limit as unlimited', () => {
        const { hasReachedLimit, remaining } = withPlan({
            usage: { products: 3, members: null },
        });

        expect(hasReachedLimit('usage.products', 2)).toBe(false);
        expect(hasReachedLimit('usage.products', 3)).toBe(true);
        expect(hasReachedLimit('usage.products', 4)).toBe(true);
        expect(hasReachedLimit('usage.members', 9999)).toBe(false);
        expect(hasReachedLimit('usage.missing', 9999)).toBe(false);

        expect(remaining('usage.products', 0)).toBe(3);
        expect(remaining('usage.products', 2)).toBe(1);
        expect(remaining('usage.products', 99)).toBe(0);
        expect(remaining('usage.members', 500)).toBeNull();
        expect(remaining('usage.missing', 500)).toBeNull();
    });

    it('denies everything when no plan is shared', () => {
        pageState.props = { kinetix_billing: { enabled: true, plan: null } };
        const { canUseFeature, hasReachedLimit, remaining, plan } =
            useKinetixPlan();

        expect(plan.value).toBeNull();
        expect(canUseFeature('capabilities.api')).toBe(false);
        // No plan → no limit information → unlimited (server-side parity).
        expect(hasReachedLimit('usage.products', 99)).toBe(false);
        expect(remaining('usage.products', 99)).toBeNull();
    });

    it('reports disabled when the prop is absent entirely', () => {
        pageState.props = {};
        const { enabled, plan, canUseFeature } = useKinetixPlan();

        expect(enabled.value).toBe(false);
        expect(plan.value).toBeNull();
        expect(canUseFeature('capabilities.api')).toBe(false);
    });
});
