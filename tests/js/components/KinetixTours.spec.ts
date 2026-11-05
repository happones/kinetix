import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import {
    afterEach,
    beforeAll,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';
import { createI18n } from 'vue-i18n';

const { pageState, driveMock, destroyMock, moveToMock, driverFactory } =
    vi.hoisted(() => {
        const driveMock = vi.fn();
        const destroyMock = vi.fn();
        const moveToMock = vi.fn();

        return {
            pageState: {
                props: {} as Record<string, unknown>,
                component: 'Kinetix/Posts/Index',
                url: '/posts?page=2',
            },
            driveMock,
            destroyMock,
            moveToMock,
            driverFactory: vi.fn(() => ({
                drive: driveMock,
                destroy: destroyMock,
                moveTo: moveToMock,
                getActiveIndex: () => undefined,
            })),
        };
    });

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => pageState,
}));

vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: vi.fn().mockResolvedValue({}),
    kinetixRoutePrefix: () => '_kinetix',
}));

vi.mock('driver.js', () => ({ driver: driverFactory }));
vi.mock('driver.js/dist/driver.css', () => ({}));

import KinetixTourTrigger from '@/components/KinetixTourTrigger.vue';
import KinetixTours from '@/components/KinetixTours.vue';
import { useKinetixToursStore } from '@/stores/kinetixTours';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: {
        en: {
            kinetix: {
                tour_next: 'Next',
                tour_previous: 'Back',
                tour_done: 'Done',
                tour_progress: '{current} of {total}',
                tour_replay: 'Show me around this page',
            },
        },
    },
});

const share = () => {
    pageState.props = {
        kinetix_tours: {
            enabled: true,
            driver: 'local',
            tours: [
                {
                    id: 'posts',
                    page: 'Kinetix/Posts/Index',
                    url: null,
                    auto: true,
                    steps: [
                        {
                            selector: '[data-tour=create]',
                            title: 'Create records',
                            description: 'Start here.',
                            side: 'bottom',
                            align: null,
                        },
                        {
                            selector: '[data-tour=filters]',
                            title: 'Filters',
                            description: null,
                            side: null,
                            align: null,
                        },
                    ],
                },
            ],
            seen: [],
        },
    };
};

const mountHost = async () => {
    const wrapper = mount(KinetixTours, { global: { plugins: [i18n] } });
    // The tour driver is a lazy chunk (warmed in beforeAll).
    await flushPromises();

    return wrapper;
};

type ToursShare = { tours: Array<Record<string, unknown>> } & Record<
    string,
    unknown
>;

const tours = () => pageState.props.kinetix_tours as ToursShare;

const automate = (on: boolean) =>
    Object.defineProperty(navigator, 'webdriver', {
        value: on,
        configurable: true,
    });

const autoStarted = async () => {
    await mountHost();
    vi.advanceTimersByTime(500);
    await flushPromises();

    return driverFactory.mock.calls.length > 0;
};

beforeAll(async () => {
    await import('@/components/Tours/ToursBody.vue');
});

describe('KinetixTours', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        vi.useFakeTimers();
        localStorage.clear();
        share();
        // happy-dom, like Playwright, reports navigator.webdriver = true.
        automate(false);
    });

    it('auto-starts the matching unseen tour with mapped driver.js steps', async () => {
        await mountHost();

        vi.advanceTimersByTime(500);
        await flushPromises();

        expect(driverFactory).toHaveBeenCalledTimes(1);
        const config = driverFactory.mock.calls[0][0] as Record<string, any>;

        expect(config.popoverClass).toBe('kinetix-tour-popover');
        expect(config.showButtons).toEqual(['next', 'previous', 'close']);
        expect(config.nextBtnText).toBe('Next');
        expect(config.progressText).toBe('{{current}} of {{total}}');
        expect(
            config.steps.map((step: any) => ({ ...step, element: undefined })),
        ).toEqual([
            {
                element: undefined,
                popover: {
                    title: 'Create records',
                    description: 'Start here.',
                    side: 'bottom',
                },
            },
            {
                element: undefined,
                popover: { title: 'Filters', description: '' },
            },
        ]);

        // Each step resolves its target when driver.js reaches it.
        const create = document.createElement('button');
        create.setAttribute('data-tour', 'create');
        document.body.appendChild(create);
        expect(config.steps[0].element()).toBe(create);
        expect(config.steps[1].element()).toBeNull();
        create.remove();
        expect(driveMock).toHaveBeenCalled();
    });

    it('highlights a target taller than the screen from its top down to what fits', async () => {
        await mountHost();
        vi.advanceTimersByTime(500);
        await flushPromises();

        const config = driverFactory.mock.calls[0][0] as Record<string, any>;
        const table = document.createElement('div');
        table.setAttribute('data-tour', 'create');
        table.getBoundingClientRect = () =>
            ({ top: 300, left: 280, width: 900, height: 1400 }) as DOMRect;
        document.body.appendChild(table);
        Object.defineProperty(window, 'innerHeight', {
            value: 800,
            configurable: true,
        });

        const target = config.steps[0].element() as HTMLElement;

        // Not the table itself: a stand-in over its top, ending where the
        // popover still fits below it on screen — so the page doesn't jump.
        expect(target).not.toBe(table);
        expect(target.getAttribute('data-kinetix-tour-target')).toBe('');
        expect(target.style.top).toBe('300px');
        expect(target.style.width).toBe('900px');
        expect(parseFloat(target.style.height)).toBe(800 - 72 - 240 - 300);

        // Torn down with the tour.
        config.onDestroyed();
        expect(document.querySelector('[data-kinetix-tour-target]')).toBeNull();
        table.remove();
    });

    it('marks the tour seen when driver.js tears down', async () => {
        await mountHost();
        vi.advanceTimersByTime(500);
        await flushPromises();

        const config = driverFactory.mock.calls[0][0] as Record<string, any>;
        config.onDestroyed();

        expect(localStorage.getItem('kinetix.tour.posts')).toBe('1');
    });

    it('does not auto-start a tour that was already seen', async () => {
        localStorage.setItem('kinetix.tour.posts', '1');
        await mountHost();

        vi.advanceTimersByTime(500);
        await flushPromises();

        expect(driverFactory).not.toHaveBeenCalled();
    });

    it('runs a manual start requested through the store', async () => {
        localStorage.setItem('kinetix.tour.posts', '1'); // seen — manual ignores it
        await mountHost();
        vi.advanceTimersByTime(500);
        await flushPromises();
        expect(driverFactory).not.toHaveBeenCalled();

        useKinetixToursStore().start('posts');
        await flushPromises();

        expect(driverFactory).toHaveBeenCalledTimes(1);
        expect(driveMock).toHaveBeenCalled();
    });

    it('shows a tour again once its version is bumped, without renaming it', async () => {
        localStorage.setItem('kinetix.tour.posts', '1'); // v1 seen
        Object.assign(tours().tours[0], { version: 2, seenKey: 'posts@v2' });

        expect(await autoStarted()).toBe(true);

        (driverFactory.mock.calls[0][0] as Record<string, any>).onDestroyed();
        expect(localStorage.getItem('kinetix.tour.posts@v2')).toBe('1');
        expect(useKinetixToursStore().hasSeen('posts')).toBe(true);
    });
});

describe('KinetixTours host shell', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        localStorage.clear();
        automate(false);
    });

    it('renders only its slot and loads no driver while tours are off or empty', async () => {
        for (const state of [
            { enabled: false, driver: 'local', tours: [], seen: [] },
            { enabled: true, driver: 'local', tours: [], seen: [] },
        ]) {
            pageState.props = { kinetix_tours: state };

            const wrapper = mount(KinetixTours, {
                global: { plugins: [i18n] },
                slots: { default: '<p class="slotted">content</p>' },
            });
            await flushPromises();

            expect(wrapper.find('.slotted').exists()).toBe(true);
            expect(wrapper.findComponent({ name: 'ToursBody' }).exists()).toBe(
                false,
            );
            wrapper.unmount();
        }
    });

    it('keeps its own slot mounted while the driver loads and after', async () => {
        share();
        // A fresh host module: its driver has not loaded yet.
        vi.resetModules();
        const { default: FreshTours } =
            await import('@/components/KinetixTours.vue');

        const wrapper = mount(FreshTours, {
            global: { plugins: [i18n] },
            slots: { default: '<p class="slotted">content</p>' },
        });
        const slotted = wrapper.find('.slotted').element;

        expect(wrapper.findComponent({ name: 'ToursBody' }).exists()).toBe(
            false,
        );
        await vi.waitFor(() =>
            expect(wrapper.findComponent({ name: 'ToursBody' }).exists()).toBe(
                true,
            ),
        );

        // The same element: never unmounted and remounted.
        expect(wrapper.find('.slotted').element).toBe(slotted);
        expect(slotted.parentNode).not.toBeNull();
        wrapper.unmount();
    });

    it('runs a launch requested before the driver had mounted', async () => {
        share();
        localStorage.setItem('kinetix.tour.posts', '1');

        const wrapper = mount(KinetixTours, { global: { plugins: [i18n] } });
        useKinetixToursStore().start('posts');
        await flushPromises();

        expect(driverFactory).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });
});

describe('KinetixTours under test automation', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        vi.useFakeTimers();
        localStorage.clear();
        share();
        automate(true);
    });

    afterEach(() => {
        automate(false);
        window.history.replaceState(null, '', '/');
    });

    it('does not auto-start when navigator.webdriver is set', async () => {
        expect(await autoStarted()).toBe(false);
    });

    it('auto-starts again when the test asks for tours', async () => {
        localStorage.setItem('kinetix.tours.automated', '1');

        expect(await autoStarted()).toBe(true);
    });

    it('auto-starts with ?kinetix-tours=1', async () => {
        window.history.replaceState(null, '', '/posts?kinetix-tours=1');

        expect(await autoStarted()).toBe(true);
    });

    it('auto-starts when skip_when_automated is off', async () => {
        tours().skipWhenAutomated = false;

        expect(await autoStarted()).toBe(true);
    });

    it('still runs a manual launch', async () => {
        await mountHost();
        useKinetixToursStore().start('posts');
        await flushPromises();

        expect(driverFactory).toHaveBeenCalledTimes(1);
    });
});

describe('KinetixTourTrigger', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        localStorage.clear();
        share();
    });

    const mountTrigger = (props: Record<string, unknown> = {}) =>
        mount(KinetixTourTrigger, { props, global: { plugins: [i18n] } });

    it('replays the tour of the current page, even one already seen', async () => {
        localStorage.setItem('kinetix.tour.posts', '1');
        const wrapper = mountTrigger();

        const button = wrapper.get('[data-kinetix-tour-trigger]');
        expect(button.text()).toBe('Show me around this page');

        await button.trigger('click');
        expect(useKinetixToursStore().activeTourId).toBe('posts');
    });

    it('finds a manual-only tour by URL', () => {
        Object.assign(tours().tours[0], {
            page: null,
            url: '/posts',
            auto: false,
        });

        expect(
            mountTrigger().find('[data-kinetix-tour-trigger]').exists(),
        ).toBe(true);
    });

    it('renders nothing on a page without a tour, or with tours off', () => {
        pageState.component = 'Kinetix/Users/Index';
        expect(mountTrigger().html()).not.toContain('button');
        pageState.component = 'Kinetix/Posts/Index';

        tours().enabled = false;
        setActivePinia(createPinia());
        expect(mountTrigger().html()).not.toContain('button');
    });

    it('names the icon-only button and hands the slot what a menu item needs', async () => {
        const icon = mountTrigger({ iconOnly: true }).get('button');
        expect(icon.attributes('aria-label')).toBe('Show me around this page');
        expect(icon.text()).toBe('');

        const custom = mount(KinetixTourTrigger, {
            global: { plugins: [i18n] },
            slots: {
                default: `<template #default="{ start, label }">
                    <a role="menuitem" @click="start">{{ label }}</a>
                </template>`,
            },
        });

        await custom.get('[role=menuitem]').trigger('click');
        expect(useKinetixToursStore().activeTourId).toBe('posts');
    });
});
