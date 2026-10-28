import { enableAutoUnmount, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: { kinetix_config: { route_prefix: '_kinetix' } },
    }),
    // No Inertia app in tests: the helper falls back to history.replaceState.
    router: {
        replace: () => {
            throw new Error('no app');
        },
    },
}));
vi.mock('@/composables/useKinetixWizard', () => ({
    useKinetixWizard: () => ({ complete: vi.fn(), status: vi.fn() }),
}));

import KinetixFormTabs from '@/components/KinetixFormTabs.vue';
import KinetixFormWizard from '@/components/KinetixFormWizard.vue';
import KinetixInfolist from '@/components/KinetixInfolist.vue';
import KinetixWizard from '@/components/KinetixWizard.vue';
import { requestedIndex } from '@/composables/useKinetixQueryParam';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            kinetix: {
                wizard_next: 'Next',
                wizard_back: 'Back',
                wizard_finish: 'Finish',
            },
        },
    },
});

afterEach(() => window.history.replaceState(null, '', '/'));
enableAutoUnmount(afterEach);

const visit = (path: string) => window.history.replaceState(null, '', path);
const query = (param: string) =>
    new URLSearchParams(window.location.search).get(param);

const field = (name: string, extra: Record<string, unknown> = {}) => ({
    type: 'text-input',
    name,
    label: name,
    columnSpan: 'full',
    ...extra,
});

const formTabs = [
    {
        type: 'tab',
        heading: 'Profile',
        urlKey: 'profile',
        schema: [field('name')],
    },
    {
        type: 'tab',
        heading: 'Security',
        urlKey: 'security',
        schema: [field('password')],
    },
];

const mountTabs = (props: Record<string, unknown> = {}) =>
    mount(KinetixFormTabs, {
        props: {
            tabs: formTabs,
            queryParam: 'tab',
            values: {},
            errors: {},
            ...props,
        },
        global: { plugins: [i18n] },
    });

const selectedTab = (wrapper: ReturnType<typeof mount>) =>
    wrapper.find('[role="tab"][aria-selected="true"]').text();

describe('requestedIndex', () => {
    const outer = [
        { urlKey: 'general', schema: [] },
        {
            urlKey: 'billing',
            schema: [
                {
                    queryParam: 'tab',
                    schema: [{ urlKey: 'plan' }, { urlKey: 'address' }],
                },
            ],
        },
    ];

    it('picks the child the URL names, or the one holding a nested group that owns it', () => {
        visit('/?tab=billing');
        expect(requestedIndex(outer, 'tab')).toBe(1);

        visit('/?tab=address');
        expect(requestedIndex(outer, 'tab')).toBe(1);
    });

    it('returns -1 when the param is off, absent or names nothing here', () => {
        visit('/?tab=billing');
        expect(requestedIndex(outer, null)).toBe(-1);
        expect(requestedIndex(outer, 'section')).toBe(-1);

        visit('/?tab=nope');
        expect(requestedIndex(outer, 'tab')).toBe(-1);
    });
});

describe('form tabs in the query string', () => {
    it('opens the tab the URL names', () => {
        visit('/users/1/edit?tab=security');

        expect(selectedTab(mountTabs())).toContain('Security');
    });

    it('writes the chosen tab into the URL, keeping the other params', async () => {
        visit('/users/1/edit?page=2');
        const wrapper = mountTabs();

        await wrapper
            .findAll('[role="tab"]')[1]
            .trigger('mousedown', { button: 0 });
        await nextTick();

        expect(query('tab')).toBe('security');
        expect(query('page')).toBe('2');
    });

    it('falls back to the first tab for a key it does not know', () => {
        visit('/users/1/edit?tab=gone');

        expect(selectedTab(mountTabs())).toContain('Profile');
    });

    it('records the jump to an errored tab too', async () => {
        visit('/users/1/edit');
        const wrapper = mountTabs();

        await wrapper.setProps({ errors: { password: 'Required' } });
        await nextTick();

        expect(selectedTab(wrapper)).toContain('Security');
        expect(query('tab')).toBe('security');
    });

    it('leaves the URL alone inside a modal or when switched off', async () => {
        visit('/users?tab=security');

        expect(selectedTab(mountTabs({ flat: true }))).toContain('Profile');
        expect(selectedTab(mountTabs({ queryParam: null }))).toContain(
            'Profile',
        );

        visit('/users');
        const modal = mountTabs({ flat: true });
        await modal
            .findAll('[role="tab"]')[1]
            .trigger('mousedown', { button: 0 });
        await nextTick();

        expect(query('tab')).toBeNull();
    });
});

describe('wizard steps in the query string', () => {
    const wizard = {
        type: 'wizard',
        queryParam: 'step',
        schema: [
            {
                type: 'wizard-step',
                heading: 'Account',
                urlKey: 'account',
                schema: [field('email', { isRequired: true })],
            },
            {
                type: 'wizard-step',
                heading: 'Company',
                urlKey: 'company',
                schema: [field('company', { isRequired: true })],
            },
            {
                type: 'wizard-step',
                heading: 'Billing',
                urlKey: 'billing',
                schema: [field('plan')],
            },
        ],
    };

    const mountWizard = (values: Record<string, unknown>) =>
        mount(KinetixFormWizard, {
            props: { comp: wizard, values, errors: {} },
            global: { plugins: [i18n] },
        });

    const currentHeading = (wrapper: ReturnType<typeof mount>) =>
        wizard.schema[wrapper.findComponent(KinetixWizard).props('step')]
            .heading;

    it('reopens the step the URL names when the steps before it are complete', () => {
        visit('/orders/1/edit?step=billing');

        const wrapper = mountWizard({ email: 'a@b.c', company: 'Acme' });

        expect(currentHeading(wrapper)).toBe('Billing');
    });

    it('never skips an incomplete step: it opens the first one instead', () => {
        visit('/orders/create?step=billing');

        const wrapper = mountWizard({ email: 'a@b.c' });

        expect(currentHeading(wrapper)).toBe('Company');
        // The URL names the step actually shown.
        expect(query('step')).toBe('company');
    });

    it('writes each step it moves to', async () => {
        visit('/orders/create');
        const wrapper = mountWizard({ email: 'a@b.c' });

        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Next')!
            .trigger('click');
        await nextTick();

        expect(query('step')).toBe('company');
    });
});

describe('infolist tabs in the query string', () => {
    const entry = (name: string) => ({
        type: 'text',
        name,
        label: name,
        state: name,
        columnSpan: 'full',
        isInline: false,
        openUrlInNewTab: false,
    });

    const infolist = {
        columns: 1,
        operation: 'view',
        schema: [
            {
                type: 'tabs',
                queryParam: 'tab',
                columnSpan: 'full',
                isInline: false,
                openUrlInNewTab: false,
                schema: [
                    {
                        type: 'tab',
                        heading: 'Overview',
                        urlKey: 'overview',
                        schema: [entry('summary')],
                    },
                    {
                        type: 'tab',
                        heading: 'Billing',
                        urlKey: 'billing',
                        schema: [
                            {
                                type: 'tabs',
                                queryParam: 'tab',
                                columnSpan: 'full',
                                schema: [
                                    {
                                        type: 'tab',
                                        heading: 'Plan',
                                        urlKey: 'plan',
                                        schema: [entry('tier')],
                                    },
                                    {
                                        type: 'tab',
                                        heading: 'Address',
                                        urlKey: 'address',
                                        schema: [entry('street')],
                                    },
                                ],
                            },
                        ],
                    },
                ],
            },
        ],
    };

    const mountInfolist = (props: Record<string, unknown> = {}) =>
        mount(KinetixInfolist, {
            props: { infolist: infolist as never, ...props },
            global: { plugins: [i18n] },
        });

    const selected = (wrapper: ReturnType<typeof mount>) =>
        wrapper
            .findAll('[role="tab"][aria-selected="true"]')
            .map((tab) => tab.text());

    it('opens the outer tab AND the nested one a link names', () => {
        visit('/customers/1?tab=address');

        expect(selected(mountInfolist())).toEqual(['Billing', 'Address']);
    });

    it('writes the clicked tab into the URL', async () => {
        visit('/customers/1');
        const wrapper = mountInfolist();

        await wrapper
            .findAll('[role="tab"]')
            .find((tab) => tab.text() === 'Billing')!
            .trigger('click');

        expect(query('tab')).toBe('billing');
    });

    it('ignores the URL inside a modal', () => {
        visit('/customers?tab=billing');

        expect(selected(mountInfolist({ flat: true }))[0]).toBe('Overview');
    });
});
