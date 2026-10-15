import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

const { pageState, driveMock, destroyMock, driverFactory } = vi.hoisted(() => {
    const driveMock = vi.fn();
    const destroyMock = vi.fn();

    return {
        pageState: {
            props: {} as Record<string, unknown>,
            component: 'Kinetix/Posts/Index',
        },
        driveMock,
        destroyMock,
        driverFactory: vi.fn(() => ({
            drive: driveMock,
            destroy: destroyMock,
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

const mountHost = () => mount(KinetixTours, { global: { plugins: [i18n] } });

describe('KinetixTours', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        vi.useFakeTimers();
        localStorage.clear();
        share();
    });

    it('auto-starts the matching unseen tour with mapped driver.js steps', async () => {
        mountHost();

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
        mountHost();
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
        mountHost();
        vi.advanceTimersByTime(500);
        await flushPromises();

        const config = driverFactory.mock.calls[0][0] as Record<string, any>;
        config.onDestroyed();

        expect(localStorage.getItem('kinetix.tour.posts')).toBe('1');
    });

    it('does not auto-start a tour that was already seen', async () => {
        localStorage.setItem('kinetix.tour.posts', '1');
        mountHost();

        vi.advanceTimersByTime(500);
        await flushPromises();

        expect(driverFactory).not.toHaveBeenCalled();
    });

    it('runs a manual start requested through the store', async () => {
        localStorage.setItem('kinetix.tour.posts', '1'); // seen — manual ignores it
        mountHost();
        vi.advanceTimersByTime(500);
        await flushPromises();
        expect(driverFactory).not.toHaveBeenCalled();

        useKinetixToursStore().start('posts');
        await flushPromises();

        expect(driverFactory).toHaveBeenCalledTimes(1);
        expect(driveMock).toHaveBeenCalled();
    });
});
