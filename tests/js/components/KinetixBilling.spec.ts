import { config, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { i18n } from './i18n';

config.global.plugins = [i18n];
import KinetixInvoicesTable from '@/components/KinetixInvoicesTable.vue';
import KinetixPlanCard from '@/components/KinetixPlanCard.vue';
import KinetixPricingTable from '@/components/KinetixPricingTable.vue';
import KinetixSubscriptionStatus from '@/components/KinetixSubscriptionStatus.vue';

const proPlan = {
    id: 1,
    name: 'Pro',
    slug: 'pro',
    description: 'For teams',
    monthlyPrice: 29,
    yearlyPrice: 290,
    features: { capabilities: { api: true, sso: false } },
    highlightedFeatures: ['Priority support', 'Unlimited projects'],
    isFeatured: true,
    isFree: false,
    sortOrder: 1,
};

describe('KinetixPlanCard', () => {
    it('shows the monthly price by default and the yearly price when cycle=yearly', async () => {
        const wrapper = mount(KinetixPlanCard, { props: { plan: proPlan } });
        expect(wrapper.text()).toContain('$29');

        await wrapper.setProps({ cycle: 'yearly' });
        expect(wrapper.text()).toContain('$290');
    });

    it('renders highlighted features and capability rows with granted/denied state', () => {
        const wrapper = mount(KinetixPlanCard, {
            props: {
                plan: proPlan,
                featureLabels: {
                    'capabilities.api': 'API access',
                    'capabilities.sso': 'SSO',
                },
            },
        });

        expect(wrapper.text()).toContain('Priority support');
        expect(wrapper.text()).toContain('API access');
        expect(wrapper.text()).toContain('SSO');
    });

    it('emits subscribe with the plan when the CTA is clicked', async () => {
        const wrapper = mount(KinetixPlanCard, { props: { plan: proPlan } });

        await wrapper.get('button').trigger('click');

        expect(wrapper.emitted('subscribe')?.[0]).toEqual([proPlan]);
    });

    it('hides the CTA and shows the active state when isActive', () => {
        const wrapper = mount(KinetixPlanCard, {
            props: {
                plan: proPlan,
                isActive: true,
                currentLabel: 'Current plan',
            },
        });

        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.text()).toContain('Current plan');
    });
});

describe('KinetixPricingTable', () => {
    const freePlan = {
        ...proPlan,
        id: 0,
        name: 'Free',
        slug: 'free',
        monthlyPrice: 0,
        yearlyPrice: null,
        isFree: true,
    };

    it('renders one card per plan and marks the current plan active', () => {
        const wrapper = mount(KinetixPricingTable, {
            props: { plans: [freePlan, proPlan], currentPlanSlug: 'pro' },
        });

        expect(wrapper.findAllComponents(KinetixPlanCard)).toHaveLength(2);
    });

    it('emits update:cycle from the toggle when yearly prices exist', async () => {
        const wrapper = mount(KinetixPricingTable, {
            props: {
                plans: [proPlan],
                showCycleToggle: true,
                cycle: 'monthly',
            },
        });

        const yearly = wrapper
            .findAll('button')
            .find((button) => button.text() === 'Yearly');
        await yearly!.trigger('click');

        expect(wrapper.emitted('update:cycle')?.[0]).toEqual(['yearly']);
    });

    it('forwards subscribe events from a card', () => {
        const wrapper = mount(KinetixPricingTable, {
            props: { plans: [proPlan] },
        });

        wrapper.findComponent(KinetixPlanCard).vm.$emit('subscribe', proPlan);

        expect(wrapper.emitted('subscribe')?.[0]).toEqual([proPlan]);
    });
});

describe('KinetixSubscriptionStatus', () => {
    it('shows the cancel button for an active subscription', () => {
        const wrapper = mount(KinetixSubscriptionStatus, {
            props: {
                subscription: {
                    active: true,
                    onGracePeriod: false,
                    status: 'active',
                    endsAt: null,
                    stripePrice: 'p',
                    onTrial: false,
                    trialEndsAt: null,
                    onGenericTrial: false,
                },
                cancelLabel: 'Cancel subscription',
            },
        });

        expect(wrapper.text()).toContain('Cancel subscription');
    });

    it('shows the resume button when on grace period and emits resume', async () => {
        const wrapper = mount(KinetixSubscriptionStatus, {
            props: {
                subscription: {
                    active: true,
                    onGracePeriod: true,
                    status: 'active',
                    endsAt: '2030-01-01',
                    stripePrice: 'p',
                    onTrial: false,
                    trialEndsAt: null,
                    onGenericTrial: false,
                },
                resumeLabel: 'Resume subscription',
            },
        });

        expect(wrapper.text()).toContain('Resume subscription');
        await wrapper.get('button').trigger('click');
        expect(wrapper.emitted('resume')).toHaveLength(1);
    });

    it('shows the trial badge and active message when on trial', () => {
        const wrapper = mount(KinetixSubscriptionStatus, {
            props: {
                subscription: {
                    active: true,
                    onGracePeriod: false,
                    status: 'trialing',
                    endsAt: null,
                    onTrial: true,
                    trialEndsAt: '2030-01-01T12:00:00',
                    onGenericTrial: false,
                },
            },
        });

        expect(wrapper.text()).toContain('Trial');
        expect(wrapper.text()).toContain('2030');
    });

    it('renders the empty state with no subscription', () => {
        const wrapper = mount(KinetixSubscriptionStatus, {
            props: {
                subscription: null,
                emptyLabel: 'No active subscription found.',
            },
        });

        expect(wrapper.text()).toContain('No active subscription found.');
        expect(wrapper.find('button').exists()).toBe(false);
    });
});

describe('KinetixInvoicesTable', () => {
    const invoices = [
        {
            id: 'in_1',
            date: 'Jan 1, 2030',
            total: '$29.00',
            status: 'paid',
            url: 'https://x/in_1.pdf',
        },
    ];

    it('renders invoice rows', () => {
        const wrapper = mount(KinetixInvoicesTable, { props: { invoices } });

        expect(wrapper.text()).toContain('Jan 1, 2030');
        expect(wrapper.text()).toContain('$29.00');
        expect(wrapper.text()).toContain('paid');
    });

    it('emits download when no downloadUrl is provided', async () => {
        const wrapper = mount(KinetixInvoicesTable, { props: { invoices } });

        await wrapper.get('button').trigger('click');

        expect(wrapper.emitted('download')?.[0]).toEqual([invoices[0]]);
    });

    it('renders an anchor when downloadUrl is provided', () => {
        const wrapper = mount(KinetixInvoicesTable, {
            props: {
                invoices,
                downloadUrl: (i: any) => `/billing/invoices/${i.id}`,
            },
        });

        expect(wrapper.get('a').attributes('href')).toBe(
            '/billing/invoices/in_1',
        );
    });

    it('shows the empty state with no invoices', () => {
        const wrapper = mount(KinetixInvoicesTable, {
            props: { invoices: [], emptyLabel: 'No invoices yet.' },
        });

        expect(wrapper.text()).toContain('No invoices yet.');
    });
});
