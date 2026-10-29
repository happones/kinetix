import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { createI18n } from 'vue-i18n';

const { pageState, driverFactory, driveMock, moveToMock, destroyMock } =
    vi.hoisted(() => {
        // Remembers the active step, as driver.js does.
        let active: number | undefined;
        const driveMock = vi.fn((index?: number) => (active = index ?? 0));
        const moveToMock = vi.fn((index: number) => (active = index));
        const destroyMock = vi.fn(() => (active = undefined));

        return {
            pageState: {
                props: {} as Record<string, unknown>,
                component: 'Employees/Edit',
                url: '/employees/1/edit',
            },
            driveMock,
            moveToMock,
            destroyMock,
            driverFactory: vi.fn(() => {
                active = undefined;

                return {
                    drive: driveMock,
                    moveTo: moveToMock,
                    destroy: destroyMock,
                    getActiveIndex: () => active,
                };
            }),
        };
    });

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => pageState,
    // No Inertia app: query-string writes fall back to history.replaceState.
    router: {
        replace: () => {
            throw new Error('no app');
        },
    },
}));
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: vi.fn().mockResolvedValue({}),
    kinetixRoutePrefix: () => '_kinetix',
}));
vi.mock('@/composables/useKinetixWizard', () => ({
    useKinetixWizard: () => ({ complete: vi.fn(), status: vi.fn() }),
}));
vi.mock('driver.js', () => ({ driver: driverFactory }));
vi.mock('driver.js/dist/driver.css', () => ({}));

import KinetixFormTabs from '@/components/KinetixFormTabs.vue';
import KinetixFormWizard from '@/components/KinetixFormWizard.vue';
import KinetixInfolist from '@/components/KinetixInfolist.vue';
import KinetixTours from '@/components/KinetixTours.vue';
import { requestTourReveal } from '@/composables/useKinetixTourReveal';

/**
 * Tours reach into tabs and wizard steps: a step pointing at a field in a
 * closed tab opens that tab first, nested groups included; a wizard never
 * skips a step whose required fields are empty, and points the tour at the
 * step's indicator instead.
 */
const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: {} } },
});

enableAutoUnmount(afterEach);
afterEach(() => window.history.replaceState(null, '', '/'));

const field = (name: string, extra: Record<string, unknown> = {}) => ({
    type: 'text-input',
    name,
    label: name,
    columnSpan: 'full',
    ...extra,
});

const tab = (heading: string, urlKey: string, schema: unknown[]) => ({
    type: 'tab',
    heading,
    urlKey,
    schema,
});

// General | Employment ( Contract | Payroll[salary] )
const employeeTabs = [
    tab('General', 'general', [field('name')]),
    tab('Employment', 'employment', [
        {
            type: 'tabs',
            queryParam: null,
            schema: [
                tab('Contract', 'contract', [field('department_id')]),
                tab('Payroll', 'payroll', [field('salary')]),
            ],
        },
    ]),
];

const mountTabs = () =>
    mount(KinetixFormTabs, {
        props: { tabs: employeeTabs, queryParam: null, values: {}, errors: {} },
        global: { plugins: [i18n] },
        attachTo: document.body,
    });

const selected = (wrapper: ReturnType<typeof mount>) =>
    wrapper
        .findAll('[role="tab"][aria-selected="true"]')
        .map((element) => element.text());

/** Repeat the request a few times, as the tour host does. */
const reveal = async (request: { field?: string; tab?: string }) => {
    let fallback: string | null = null;

    for (let attempt = 0; attempt < 4 && fallback === null; attempt++) {
        fallback = requestTourReveal(request);
        await nextTick();
        await nextTick();
    }

    return fallback;
};

describe('tour reveal: form tabs', () => {
    it('renders stable hooks on tab triggers and field wrappers', () => {
        const wrapper = mountTabs();

        expect(wrapper.find('[data-kinetix-tab="employment"]').exists()).toBe(
            true,
        );
        expect(wrapper.find('[data-kinetix-field="name"]').exists()).toBe(true);
    });

    it('opens the tab, and the nested tab, holding a field', async () => {
        const wrapper = mountTabs();

        expect(wrapper.find('[data-kinetix-field="salary"]').exists()).toBe(
            false,
        );

        await reveal({ field: 'salary' });

        expect(selected(wrapper)).toEqual(['Employment', 'Payroll']);
        expect(wrapper.find('[data-kinetix-field="salary"]').exists()).toBe(
            true,
        );
    });

    it('opens a tab by key, nested or not', async () => {
        const wrapper = mountTabs();

        await reveal({ tab: 'payroll' });

        expect(selected(wrapper)).toEqual(['Employment', 'Payroll']);
    });

    it('leaves the tabs alone for a target they do not hold', async () => {
        const wrapper = mountTabs();

        await reveal({ field: 'elsewhere' });

        expect(selected(wrapper)).toEqual(['General']);
    });
});

describe('tour reveal: wizard steps', () => {
    const wizard = {
        type: 'wizard',
        queryParam: null,
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
                schema: [field('company')],
            },
        ],
    };

    const mountWizard = (values: Record<string, unknown>) =>
        mount(KinetixFormWizard, {
            props: { comp: wizard, values, errors: {} },
            global: { plugins: [i18n] },
            attachTo: document.body,
        });

    it('names each step in the indicator', () => {
        const wrapper = mountWizard({});

        expect(
            wrapper.find('[data-kinetix-wizard-step="company"]').exists(),
        ).toBe(true);
    });

    it('opens the step holding a field once the steps before it are complete', async () => {
        const wrapper = mountWizard({ email: 'a@b.c' });

        expect(await reveal({ field: 'company' })).toBeNull();
        expect(wrapper.find('[data-kinetix-field="company"]').exists()).toBe(
            true,
        );
    });

    it('never skips an incomplete step: it points at the indicator instead', async () => {
        const wrapper = mountWizard({});

        expect(await reveal({ field: 'company' })).toBe(
            '[data-kinetix-wizard-step="company"]',
        );
        expect(wrapper.find('[data-kinetix-field="email"]').exists()).toBe(
            true,
        );
    });
});

describe('tour reveal: infolist tabs', () => {
    const entry = (name: string) => ({
        type: 'text',
        name,
        label: name,
        state: name,
        columnSpan: 'full',
        isInline: false,
        openUrlInNewTab: false,
    });

    it('opens a tab by key', async () => {
        const wrapper = mount(KinetixInfolist, {
            props: {
                infolist: {
                    columns: 1,
                    operation: 'view',
                    schema: [
                        {
                            type: 'tabs',
                            queryParam: null,
                            columnSpan: 'full',
                            isInline: false,
                            openUrlInNewTab: false,
                            schema: [
                                tab('Overview', 'overview', [entry('summary')]),
                                tab('Billing', 'billing', [entry('plan')]),
                            ],
                        },
                    ],
                } as never,
            },
            global: { plugins: [i18n] },
            attachTo: document.body,
        });

        expect(wrapper.find('[data-kinetix-tab="billing"]').exists()).toBe(
            true,
        );

        await reveal({ tab: 'billing' });

        expect(selected(wrapper)).toEqual(['Billing']);
    });
});

describe('KinetixTours drives reveals', () => {
    const step = (over: Record<string, unknown>) => ({
        title: 'Step',
        description: null,
        side: null,
        align: null,
        reveal: null,
        optional: false,
        ...over,
    });

    const share = (steps: unknown[]) => {
        pageState.props = {
            kinetix_tours: {
                enabled: true,
                driver: 'local',
                seen: [],
                skipWhenAutomated: false,
                tours: [
                    {
                        id: 'employees',
                        page: 'Employees/Edit',
                        url: null,
                        auto: true,
                        steps,
                    },
                ],
            },
        };
    };

    const config = () =>
        driverFactory.mock.calls[0][0] as unknown as Record<string, any>;

    const started = async () => {
        mount(KinetixTours, { global: { plugins: [i18n] } });
        await vi.waitFor(() => expect(driveMock).toHaveBeenCalled(), {
            timeout: 2000,
        });
    };

    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        localStorage.clear();
    });

    it('opens the tab holding the first step’s field before driving', async () => {
        share([
            step({
                selector: '[data-kinetix-field="salary"]',
                reveal: { field: 'salary' },
            }),
        ]);
        const tabs = mountTabs();

        await started();

        expect(driveMock).toHaveBeenCalledWith(0);
        expect(selected(tabs)).toEqual(['Employment', 'Payroll']);
        expect(config().steps[0].element()).toBe(
            tabs.get('[data-kinetix-field="salary"]').element,
        );
    });

    it('reveals the next step’s target when moving on', async () => {
        share([
            step({ selector: '[data-kinetix-tab="general"]' }),
            step({
                selector: '[data-kinetix-field="department_id"]',
                reveal: { field: 'department_id' },
            }),
        ]);
        const tabs = mountTabs();

        await started();
        config().onNextClick();

        await vi.waitFor(() => expect(moveToMock).toHaveBeenCalledWith(1));
        expect(selected(tabs)).toEqual(['Employment', 'Contract']);
    });

    it('leaves out an optional step whose target is missing', async () => {
        share([
            step({ selector: '[data-action="export"]', optional: true }),
            step({ selector: '[data-kinetix-tab="general"]' }),
        ]);
        mountTabs();

        await started();

        expect(config().steps).toHaveLength(1);
        expect(config().showProgress).toBe(false);
    });

    it('closes the tour after the last step', async () => {
        share([step({ selector: '[data-kinetix-tab="general"]' })]);
        mountTabs();

        await started();
        config().onNextClick();
        await flushPromises();

        expect(destroyMock).toHaveBeenCalled();
    });

    it('warns once in development about a target that is not there', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        share([step({ selector: '[data-action="gone"]' })]);

        await started();
        config().steps[0].element();
        config().steps[0].element();

        expect(
            warn.mock.calls.filter(([m]) => String(m).includes('gone')),
        ).toHaveLength(1);
        warn.mockRestore();
    });
});
