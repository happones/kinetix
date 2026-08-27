import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const { pageState } = vi.hoisted(() => ({
    pageState: { props: {} as Record<string, unknown> },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => pageState,
}));

import KinetixEntitled from '@/components/KinetixEntitled.vue';

type Verdict = {
    allowed: boolean;
    reason: string | null;
    remaining: number | null;
};

const share = (entitlements: Record<string, Verdict>) => {
    pageState.props = {
        kinetix_entitlements: { enabled: true, entitlements },
    };
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

describe('KinetixEntitled', () => {
    it('renders the default slot when the entitlement allows', () => {
        share({ 'projects.create': allow() });

        const wrapper = mount(KinetixEntitled, {
            props: { name: 'projects.create' },
            slots: {
                default: '<span>create</span>',
                denied: '<span>nope</span>',
            },
        });

        expect(wrapper.text()).toContain('create');
        expect(wrapper.text()).not.toContain('nope');
    });

    it('exposes the remaining count to the default slot', () => {
        share({ 'projects.create': allow(3) });

        const wrapper = mount(KinetixEntitled, {
            props: { name: 'projects.create' },
            slots: {
                default:
                    '<template #default="{ remaining }">{{ remaining }} left</template>',
            },
        });

        expect(wrapper.text()).toContain('3 left');
    });

    it('tells the denied slot WHICH layer refused', () => {
        share({ 'projects.create': deny('permission') });

        const wrapper = mount(KinetixEntitled, {
            props: { name: 'projects.create' },
            slots: {
                default: '<span>create</span>',
                denied: '<template #denied="{ reason }">denied: {{ reason }}</template>',
            },
        });

        expect(wrapper.text()).toContain('denied: permission');
        expect(wrapper.text()).not.toContain('create');
    });

    it('marks plan and limit denials as upsells, and others not', () => {
        const upsellText = (reason: string) => {
            share({ thing: deny(reason) });

            return mount(KinetixEntitled, {
                props: { name: 'thing' },
                slots: {
                    denied: '<template #denied="{ isUpsell }">{{ isUpsell ? "upgrade" : "refused" }}</template>',
                },
            }).text();
        };

        expect(upsellText('plan')).toContain('upgrade');
        expect(upsellText('limit')).toContain('upgrade');
        expect(upsellText('permission')).toContain('refused');
        expect(upsellText('flag')).toContain('refused');
    });

    it('renders nothing when denied with no denied slot', () => {
        share({ 'beta.thing': deny('flag') });

        const wrapper = mount(KinetixEntitled, {
            props: { name: 'beta.thing' },
            slots: { default: '<span>beta</span>' },
        });

        expect(wrapper.text()).toBe('');
    });

    it('denies an entitlement the server never shared (fails closed)', () => {
        share({});

        const wrapper = mount(KinetixEntitled, {
            props: { name: 'never.declared' },
            slots: {
                default: '<span>yes</span>',
                denied: '<template #denied="{ reason }">{{ reason }}</template>',
            },
        });

        expect(wrapper.text()).toBe('undefined');
    });

    it('requires all names by default and any with require-any', () => {
        share({ a: allow(), b: deny('plan') });

        const all = mount(KinetixEntitled, {
            props: { names: ['a', 'b'] },
            slots: { default: '<span>yes</span>', denied: '<span>no</span>' },
        });
        expect(all.text()).toBe('no');

        const any = mount(KinetixEntitled, {
            props: { names: ['a', 'b'], requireAny: true },
            slots: { default: '<span>yes</span>', denied: '<span>no</span>' },
        });
        expect(any.text()).toBe('yes');
    });

    it('reports the first denied name when several are required', () => {
        share({ a: allow(), b: deny('limit', 0) });

        const wrapper = mount(KinetixEntitled, {
            props: { names: ['a', 'b'] },
            slots: {
                denied: '<template #denied="{ reason, remaining }">{{ reason }}/{{ remaining }}</template>',
            },
        });

        expect(wrapper.text()).toBe('limit/0');
    });
});
