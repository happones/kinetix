import { describe, expect, it, vi } from 'vitest';

const { pageState } = vi.hoisted(() => ({
    pageState: { props: {} as Record<string, unknown> },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => pageState,
}));

import { useKinetixEntitlement } from '@/composables/useKinetixEntitlement';

type Verdict = {
    allowed: boolean;
    reason: string | null;
    remaining: number | null;
};

const share = (entitlements: Record<string, Verdict>, enabled = true) => {
    pageState.props = { kinetix_entitlements: { enabled, entitlements } };
};

const allow = (remaining: number | null = null): Verdict => ({
    allowed: true,
    reason: null,
    remaining,
});

const deny = (reason: string, remaining: number | null = null): Verdict => ({
    allowed: false,
    reason,
    remaining,
});

describe('useKinetixEntitlement', () => {
    it('reads allow / deny verdicts', () => {
        share({ a: allow(), b: deny('plan') });

        const { allows, denies } = useKinetixEntitlement();

        expect(allows('a')).toBe(true);
        expect(denies('a')).toBe(false);
        expect(allows('b')).toBe(false);
        expect(denies('b')).toBe(true);
    });

    it('reports which layer refused, and null when allowed', () => {
        share({ a: allow(), b: deny('flag'), c: deny('permission') });

        const { reason } = useKinetixEntitlement();

        expect(reason('a')).toBeNull();
        expect(reason('b')).toBe('flag');
        expect(reason('c')).toBe('permission');
    });

    it('treats only plan and limit denials as upsells', () => {
        share({
            plan: deny('plan'),
            limit: deny('limit'),
            perm: deny('permission'),
            flag: deny('flag'),
            ok: allow(),
        });

        const { isUpsell } = useKinetixEntitlement();

        expect(isUpsell('plan')).toBe(true);
        expect(isUpsell('limit')).toBe(true);
        expect(isUpsell('perm')).toBe(false);
        expect(isUpsell('flag')).toBe(false);
        expect(isUpsell('ok')).toBe(false);
    });

    it('exposes the remaining count, null when unlimited', () => {
        share({
            counted: allow(3),
            unlimited: allow(),
            capped: deny('limit', 0),
        });

        const { remaining } = useKinetixEntitlement();

        expect(remaining('counted')).toBe(3);
        expect(remaining('unlimited')).toBeNull();
        expect(remaining('capped')).toBe(0);
    });

    it('fails closed for an entitlement the server never shared', () => {
        share({ a: allow() });

        const { allows, reason, remaining } = useKinetixEntitlement();

        // Mirrors the server: an undeclared name is DENIED, never allowed.
        expect(allows('never.declared')).toBe(false);
        expect(reason('never.declared')).toBe('undefined');
        expect(remaining('never.declared')).toBeNull();
    });

    it('fails closed when the module is off (the prop is empty)', () => {
        share({}, false);

        const { enabled, allows } = useKinetixEntitlement();

        expect(enabled.value).toBe(false);
        expect(allows('projects.create')).toBe(false);
    });

    it('fails closed when the prop is missing entirely', () => {
        pageState.props = {};

        const { enabled, allows } = useKinetixEntitlement();

        expect(enabled.value).toBe(false);
        expect(allows('projects.create')).toBe(false);
    });

    it('checks all / any across several entitlements', () => {
        share({ a: allow(), b: allow(), c: deny('plan') });

        const { allowsAll, allowsAny } = useKinetixEntitlement();

        expect(allowsAll(['a', 'b'])).toBe(true);
        expect(allowsAll(['a', 'c'])).toBe(false);
        expect(allowsAny(['a', 'c'])).toBe(true);
        expect(allowsAny(['c'])).toBe(false);
    });

    it('exposes the raw verdict', () => {
        share({ a: deny('limit', 0) });

        const { verdict } = useKinetixEntitlement();

        expect(verdict('a')).toEqual({
            allowed: false,
            reason: 'limit',
            remaining: 0,
        });
    });
});
