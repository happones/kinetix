import { config, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { i18n } from './i18n';

config.global.plugins = [i18n];
import KinetixInvoicesTable from '@/components/KinetixInvoicesTable.vue';
import KinetixPaymentMethods from '@/components/KinetixPaymentMethods.vue';
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

    it('shows the trial badge when plan has trialDays and showTrial is true', () => {
        const wrapper = mount(KinetixPlanCard, {
            props: {
                plan: {
                    ...proPlan,
                    trialDays: 14,
                },
                showTrial: true,
            },
        });

        expect(wrapper.text()).toContain('14-day trial');
    });

    it('hides the trial badge when showTrial is false', () => {
        const wrapper = mount(KinetixPlanCard, {
            props: {
                plan: {
                    ...proPlan,
                    trialDays: 14,
                },
                showTrial: false,
            },
        });

        expect(wrapper.text()).not.toContain('14-day trial');
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

    it('holds plans back with the reason, for all or per plan', () => {
        const locked = mount(KinetixPricingTable, {
            props: {
                plans: [freePlan, proPlan],
                canSubscribe: false,
                disabledReason: 'Your free trial is running.',
            },
        });

        expect(
            locked.get('[data-test="pricing-disabled-reason"]').text(),
        ).toContain('Your free trial is running.');
        for (const button of locked.findAll('button')) {
            expect(button.attributes('disabled')).toBeDefined();
            expect(button.attributes('aria-describedby')).toBe(
                'kinetix-pricing-disabled-reason',
            );
        }

        const perPlan = mount(KinetixPricingTable, {
            props: {
                plans: [freePlan, proPlan],
                canSubscribe: (plan: { slug: string }) => plan.slug === 'pro',
            },
        });
        const [free, pro] = perPlan.findAllComponents(KinetixPlanCard);
        expect(free.props('canSubscribe')).toBe(false);
        expect(pro.props('canSubscribe')).toBe(true);
        expect(
            perPlan.find('[data-test="pricing-disabled-reason"]').exists(),
        ).toBe(false);
    });
});

describe('KinetixSubscriptionStatus', () => {
    it('names the trial plan as the reader knows it, in a warning alert', () => {
        const wrapper = mount(KinetixSubscriptionStatus, {
            props: {
                subscription: {
                    active: false,
                    onGracePeriod: false,
                    status: null,
                    endsAt: null,
                    stripePrice: null,
                    onTrial: true,
                    trialEndsAt: '2030-01-15T00:00:00Z',
                    onGenericTrial: true,
                    trialPlan: 'pro',
                    trialPlanName: 'Pro',
                },
            },
        });

        expect(wrapper.text()).toContain('Pro');
        expect(wrapper.text()).not.toMatch(/plan pro\b/);
        expect(wrapper.find('[role="status"], [role="alert"]').exists()).toBe(
            true,
        );
    });

    it('offers no cancel for a no-card trial, which has nothing to cancel', () => {
        const wrapper = mount(KinetixSubscriptionStatus, {
            props: {
                subscription: {
                    active: false,
                    onGracePeriod: false,
                    status: null,
                    endsAt: null,
                    stripePrice: null,
                    onTrial: true,
                    trialEndsAt: '2030-01-15T00:00:00Z',
                    onGenericTrial: true,
                    trialPlan: null,
                    trialPlanName: null,
                },
            },
        });

        expect(wrapper.find('button').exists()).toBe(false);
    });

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

describe('billing controls for a viewer who cannot manage billing', () => {
    const activeSubscription = {
        active: true,
        onGracePeriod: false,
        status: 'active',
        endsAt: null,
        stripePrice: 'p',
        onTrial: false,
        trialEndsAt: null,
        onGenericTrial: false,
    };

    it('hides cancel and resume on the subscription status', () => {
        const managing = mount(KinetixSubscriptionStatus, {
            props: { subscription: activeSubscription },
        });
        expect(managing.find('button').exists()).toBe(true);

        const cancel = mount(KinetixSubscriptionStatus, {
            props: { subscription: activeSubscription, canManage: false },
        });
        expect(cancel.find('button').exists()).toBe(false);

        const resume = mount(KinetixSubscriptionStatus, {
            props: {
                subscription: {
                    ...activeSubscription,
                    onGracePeriod: true,
                    endsAt: '2030-06-15T12:00:00Z',
                },
                canManage: false,
            },
        });
        expect(resume.find('button').exists()).toBe(false);
        expect(resume.text()).toContain('2030');
    });

    const card = {
        id: 'pm_1',
        brand: 'visa',
        last4: '4242',
        expMonth: 12,
        expYear: 2030,
    };

    it('offers add and remove on payment methods by default', () => {
        const wrapper = mount(KinetixPaymentMethods, {
            props: {
                paymentMethods: [card],
                selectedId: 'pm_1',
                publishableKey: 'pk_test',
            },
        });

        expect(wrapper.findAll('[role="radio"]')).toHaveLength(2);
        expect(wrapper.find('[aria-label="Remove card"]').exists()).toBe(true);
    });

    it('hides add and remove on payment methods with canManage=false', () => {
        const wrapper = mount(KinetixPaymentMethods, {
            props: {
                paymentMethods: [card],
                selectedId: 'pm_1',
                publishableKey: 'pk_test',
                setupClientSecret: 'seti_secret',
                canManage: false,
            },
        });

        const radios = wrapper.findAll('[role="radio"]');
        expect(radios).toHaveLength(1);
        expect(radios[0].text()).toContain('4242');
        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.text()).toContain(
            "You can't change this team's billing.",
        );

        const empty = mount(KinetixPaymentMethods, {
            props: {
                paymentMethods: [],
                selectedId: 'new',
                publishableKey: 'pk_test',
                setupClientSecret: 'seti_secret',
                canManage: false,
            },
        });
        expect(empty.find('[role="radio"]').exists()).toBe(false);
        expect(empty.find('button').exists()).toBe(false);
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
        // Stripe's raw status, said in the reader's language.
        expect(wrapper.text()).toContain('Paid');
        expect(wrapper.text()).not.toContain('paid');
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
