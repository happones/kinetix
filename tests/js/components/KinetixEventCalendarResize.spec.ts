import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

const reloadMock = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: {} }),
    router: { reload: (...args: unknown[]) => reloadMock(...args) },
}));
const fetchMock = vi.fn().mockResolvedValue({ status: 'success' });
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));
const toastError = vi.fn();
vi.mock('vue-sonner', () => ({
    toast: {
        success: vi.fn(),
        error: (...args: unknown[]) => toastError(...args),
    },
}));
const announceMock = vi.fn();
vi.mock('@/composables/useKinetixAnnounce', () => ({
    useKinetixAnnounce: () => ({ announce: announceMock }),
}));

import KinetixEventCalendar from '@/components/KinetixEventCalendar.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: {} } },
});

type Flags = { moveable?: boolean; resizable?: boolean };

const launch = {
    id: 1,
    title: 'Launch',
    start: '2026-06-15T09:00:00+00:00',
    end: '2026-06-15T10:30:00+00:00',
    allDay: false,
    color: '#22c55e',
    url: null,
    description: null,
    actions: [],
};

const makeCalendar = (
    flags: Flags = { moveable: true, resizable: true },
    events: unknown[] = [launch],
) => ({
    heading: null,
    timezone: 'UTC',
    model: 'signed-descriptor',
    ...flags,
    events,
});

// The week grid runs 08:00–17:00: 9 hours of 64px rows, 540 minutes.
const GRID_PX = 9 * 64;
const yAt = (hour: number, minute = 0): number =>
    (((hour - 8) * 60 + minute) / 540) * GRID_PX;

const mountIt = (props: Record<string, unknown> = {}) =>
    mount(KinetixEventCalendar, {
        props: {
            calendar: makeCalendar(),
            locale: 'en-US',
            views: ['month', 'week'],
            view: 'week',
            startHour: 8,
            endHour: 17,
            ...props,
        },
        global: { plugins: [i18n] },
        attachTo: document.body,
    });

type Wrapper = ReturnType<typeof mountIt>;

const blockOf = (w: Wrapper, id: number) =>
    w.get(`[data-calendar-event="${id}"]`);

/** Give a day column of the hour grid a laid-out box. */
const layOutColumn = (w: Wrapper, key: string): void => {
    const column = w.get(`[data-calendar-column="${key}"]`).element;
    vi.spyOn(column, 'getBoundingClientRect').mockReturnValue({
        top: 0,
        bottom: GRID_PX,
        left: 0,
        right: 100,
        width: 100,
        height: GRID_PX,
        x: 0,
        y: 0,
        toJSON: () => ({}),
    });
};

const pointer = (type: string, init: PointerEventInit = {}): PointerEvent =>
    new PointerEvent(type, {
        bubbles: true,
        cancelable: true,
        pointerType: 'mouse',
        button: 0,
        isPrimary: true,
        ...init,
    });

const resizeCall = () =>
    fetchMock.mock.calls.find((c) =>
        String(c[0]).endsWith('/tables/calendar-resize'),
    );

const flush = async (w: Wrapper): Promise<void> => {
    await Promise.resolve();
    await Promise.resolve();
    await w.vm.$nextTick();
};

describe('KinetixEventCalendar resizing', () => {
    const elementFromPoint = document.elementFromPoint;

    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-06-15T12:00:00Z'));
        fetchMock.mockClear().mockResolvedValue({ status: 'success' });
        toastError.mockClear();
        reloadMock.mockClear();
        announceMock.mockClear();
    });

    afterEach(() => {
        vi.useRealTimers();
        document.elementFromPoint = elementFromPoint;
        document.body.innerHTML = '';
    });

    it('a calendar that only moves has no end handles', () => {
        const w = mountIt({ calendar: makeCalendar({ moveable: true }) });

        expect(w.find('[data-calendar-resize]').exists()).toBe(false);
    });

    it('a calendar that only resizes has handles but nothing draggable', () => {
        const w = mountIt({
            calendar: makeCalendar({ moveable: false, resizable: true }),
        });

        expect(w.find('[data-calendar-resize]').exists()).toBe(true);
        expect(w.find('[draggable="true"]').exists()).toBe(false);
        expect(w.find('.sr-only').text()).toBe(
            'kinetix.calendar_keyboard_hint_resize',
        );
        expect(blockOf(w, 1).attributes('aria-describedby')).toBeTruthy();
    });

    it('dragging the bottom edge previews the snapped end and saves it on release', async () => {
        const w = mountIt();
        layOutColumn(w, '2026-06-15');
        const handle = blockOf(w, 1).get('[data-calendar-resize]').element;

        // 90 of the grid's 540 minutes.
        expect(blockOf(w, 1).attributes('style')).toContain('height: 16.66');

        handle.dispatchEvent(pointer('pointerdown', { clientY: yAt(10, 30) }));
        // 12:08 snaps to 12:15.
        window.dispatchEvent(pointer('pointermove', { clientY: yAt(12, 8) }));
        await w.vm.$nextTick();

        // The block grows while dragging, before anything is saved.
        expect(blockOf(w, 1).attributes('style')).toContain('height: 36.11');
        expect(fetchMock).not.toHaveBeenCalled();

        window.dispatchEvent(pointer('pointerup', { clientY: yAt(12, 8) }));
        await flush(w);

        expect(resizeCall()![1].body).toEqual({
            model: 'signed-descriptor',
            recordId: 1,
            end: '2026-06-15T12:15:00.000Z',
        });
        expect(reloadMock).toHaveBeenCalledTimes(1);
        expect(w.emitted('event-resized')![0][1]).toBe(
            '2026-06-15T12:15:00.000Z',
        );
    });

    it('dragging above the start holds the event at 15 minutes', async () => {
        const w = mountIt();
        layOutColumn(w, '2026-06-15');
        const handle = blockOf(w, 1).get('[data-calendar-resize]').element;

        handle.dispatchEvent(pointer('pointerdown', { clientY: yAt(10, 30) }));
        window.dispatchEvent(pointer('pointermove', { clientY: yAt(8) }));
        window.dispatchEvent(pointer('pointerup', { clientY: yAt(8) }));
        await flush(w);

        expect(resizeCall()![1].body.end).toBe('2026-06-15T09:15:00.000Z');
    });

    it('letting go where the event already ends saves nothing', async () => {
        const w = mountIt();
        layOutColumn(w, '2026-06-15');
        const handle = blockOf(w, 1).get('[data-calendar-resize]').element;

        handle.dispatchEvent(pointer('pointerdown', { clientY: yAt(10, 30) }));
        window.dispatchEvent(pointer('pointermove', { clientY: yAt(10, 33) }));
        window.dispatchEvent(pointer('pointerup', { clientY: yAt(10, 33) }));
        await flush(w);

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('a jitter on the handle of an event ending past the grid saves nothing', async () => {
        // 15:00–20:00 on an 08:00–17:00 grid: the handle sits at 17:00.
        const late = {
            ...launch,
            start: '2026-06-15T15:00:00+00:00',
            end: '2026-06-15T20:00:00+00:00',
        };
        const w = mountIt({ calendar: makeCalendar(undefined, [late]) });
        layOutColumn(w, '2026-06-15');
        const handle = blockOf(w, 1).get('[data-calendar-resize]').element;

        handle.dispatchEvent(pointer('pointerdown', { clientY: yAt(17) - 2 }));
        window.dispatchEvent(pointer('pointermove', { clientY: yAt(17) - 1 }));
        window.dispatchEvent(pointer('pointerup', { clientY: yAt(17) - 1 }));
        await flush(w);

        expect(fetchMock).not.toHaveBeenCalled();

        // A real drag still resizes it.
        handle.dispatchEvent(pointer('pointerdown', { clientY: yAt(17) - 2 }));
        window.dispatchEvent(pointer('pointermove', { clientY: yAt(16) }));
        window.dispatchEvent(pointer('pointerup', { clientY: yAt(16) }));
        await flush(w);

        expect(resizeCall()![1].body.end).toBe('2026-06-15T16:00:00.000Z');
    });

    it('a second finger neither steers nor ends the resize', async () => {
        const w = mountIt();
        layOutColumn(w, '2026-06-15');
        const handle = blockOf(w, 1).get('[data-calendar-resize]').element;
        const finger = { pointerType: 'touch', pointerId: 1 };
        const other = { pointerType: 'touch', pointerId: 2, isPrimary: false };

        handle.dispatchEvent(
            pointer('pointerdown', { ...finger, clientY: yAt(10, 30) }),
        );
        window.dispatchEvent(
            pointer('pointermove', { ...other, clientY: yAt(14) }),
        );
        window.dispatchEvent(
            pointer('pointerup', { ...other, clientY: yAt(14) }),
        );
        await flush(w);
        expect(fetchMock).not.toHaveBeenCalled();

        window.dispatchEvent(
            pointer('pointermove', { ...finger, clientY: yAt(12) }),
        );
        window.dispatchEvent(
            pointer('pointerup', { ...finger, clientY: yAt(12) }),
        );
        await flush(w);

        expect(resizeCall()![1].body.end).toBe('2026-06-15T12:00:00.000Z');
    });

    it('Escape puts the event back and saves nothing', async () => {
        const w = mountIt();
        layOutColumn(w, '2026-06-15');
        const handle = blockOf(w, 1).get('[data-calendar-resize]').element;

        handle.dispatchEvent(pointer('pointerdown', { clientY: yAt(10, 30) }));
        window.dispatchEvent(pointer('pointermove', { clientY: yAt(14) }));
        await w.vm.$nextTick();
        expect(blockOf(w, 1).attributes('style')).not.toContain(
            'height: 16.66',
        );

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await w.vm.$nextTick();
        window.dispatchEvent(pointer('pointerup', { clientY: yAt(14) }));
        await flush(w);

        expect(blockOf(w, 1).attributes('style')).toContain('height: 16.66');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('a refused resize puts the end back and toasts', async () => {
        fetchMock.mockRejectedValueOnce(new Error('forbidden'));
        const w = mountIt();
        layOutColumn(w, '2026-06-15');
        const handle = blockOf(w, 1).get('[data-calendar-resize]').element;

        handle.dispatchEvent(pointer('pointerdown', { clientY: yAt(10, 30) }));
        window.dispatchEvent(pointer('pointermove', { clientY: yAt(14) }));
        window.dispatchEvent(pointer('pointerup', { clientY: yAt(14) }));
        await flush(w);

        expect(toastError).toHaveBeenCalledWith(
            'kinetix.calendar_resize_failed',
        );
        expect(reloadMock).not.toHaveBeenCalled();
        expect(blockOf(w, 1).attributes('style')).toContain('height: 16.66');
    });

    it('clicking the handle never opens the event details', async () => {
        const w = mountIt();

        await blockOf(w, 1).get('[data-calendar-resize]').trigger('click');

        expect(w.emitted('event-click')).toBeUndefined();
    });

    it('a native drag never starts from the end handle', async () => {
        const w = mountIt();
        layOutColumn(w, '2026-06-15');
        const handle = blockOf(w, 1).get('[data-calendar-resize]').element;

        handle.dispatchEvent(pointer('pointerdown', { clientY: yAt(10, 30) }));
        const dragstart = new Event('dragstart', {
            bubbles: true,
            cancelable: true,
        });
        blockOf(w, 1).element.dispatchEvent(dragstart);
        await w.vm.$nextTick();

        expect(dragstart.defaultPrevented).toBe(true);
        // No move in flight: the block isn't dimmed as a drag source.
        expect(blockOf(w, 1).classes()).not.toContain('pointer-events-none');
        window.dispatchEvent(pointer('pointerup'));
    });

    it('in the month view the handle sits on the last day and drags across days', async () => {
        const w = mountIt({
            view: 'month',
            calendar: makeCalendar({ moveable: false, resizable: true }, [
                {
                    ...launch,
                    end: '2026-06-16T10:30:00+00:00',
                },
            ]),
        });

        const day15 = w.get('[data-calendar-drop="day:2026-06-15"]');
        const day16 = w.get('[data-calendar-drop="day:2026-06-16"]');
        expect(day15.find('[data-calendar-resize]').exists()).toBe(false);
        const handle = day16.get('[data-calendar-resize]').element;

        const day18 = w.get('[data-calendar-drop="day:2026-06-18"]').element;
        document.elementFromPoint = vi.fn(() => day18);

        handle.dispatchEvent(pointer('pointerdown'));
        window.dispatchEvent(pointer('pointermove', { clientX: 400 }));
        await w.vm.$nextTick();

        // The event now covers the 18th while dragging.
        expect(
            w
                .get('[data-calendar-drop="day:2026-06-18"]')
                .find('[data-calendar-event="1"]')
                .exists(),
        ).toBe(true);

        window.dispatchEvent(pointer('pointerup', { clientX: 400 }));
        await flush(w);

        // Two days later, at the same time of day.
        expect(resizeCall()![1].body.end).toBe('2026-06-18T10:30:00.000Z');
    });

    it('an all-day event ends on the day it is dragged to', async () => {
        const w = mountIt({
            view: 'month',
            calendar: makeCalendar({ moveable: false, resizable: true }, [
                {
                    ...launch,
                    start: '2026-06-15T00:00:00+00:00',
                    end: null,
                    allDay: true,
                },
            ]),
        });
        const handle = w
            .get('[data-calendar-drop="day:2026-06-15"]')
            .get('[data-calendar-resize]').element;
        const day17 = w.get('[data-calendar-drop="day:2026-06-17"]').element;
        document.elementFromPoint = vi.fn(() => day17);

        handle.dispatchEvent(pointer('pointerdown'));
        window.dispatchEvent(pointer('pointermove', { clientX: 300 }));
        window.dispatchEvent(pointer('pointerup', { clientX: 300 }));
        await flush(w);

        // The end day is inclusive: midnight of the 17th.
        expect(resizeCall()![1].body.end).toBe('2026-06-17T00:00:00.000Z');
    });

    it('Alt+Shift+ArrowDown adds 15 minutes in the hour grid and announces it', async () => {
        const w = mountIt();

        await blockOf(w, 1).trigger('keydown', {
            key: 'ArrowDown',
            altKey: true,
            shiftKey: true,
        });
        await flush(w);

        expect(resizeCall()![1].body.end).toBe('2026-06-15T10:45:00.000Z');
        expect(announceMock).toHaveBeenCalledWith(
            'kinetix.calendar_resized_to',
        );
    });

    it('Alt+Shift+ArrowRight adds a day', async () => {
        const w = mountIt({ view: 'month' });

        await blockOf(w, 1).trigger('keydown', {
            key: 'ArrowRight',
            altKey: true,
            shiftKey: true,
        });
        await flush(w);

        expect(resizeCall()![1].body.end).toBe('2026-06-16T10:30:00.000Z');
        expect(
            fetchMock.mock.calls.some((c) =>
                String(c[0]).endsWith('/tables/calendar-move'),
            ),
        ).toBe(false);
    });

    it('Alt+Shift+ArrowUp never shortens an event below 15 minutes', async () => {
        const w = mountIt({
            calendar: makeCalendar(undefined, [
                { ...launch, end: '2026-06-15T09:15:00+00:00' },
            ]),
        });

        await blockOf(w, 1).trigger('keydown', {
            key: 'ArrowUp',
            altKey: true,
            shiftKey: true,
        });
        await flush(w);

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('Alt+Shift+arrows still move events on a calendar that only moves', async () => {
        const w = mountIt({
            view: 'month',
            calendar: makeCalendar({ moveable: true }),
        });

        await blockOf(w, 1).trigger('keydown', {
            key: 'ArrowRight',
            altKey: true,
            shiftKey: true,
        });
        await flush(w);

        expect(resizeCall()).toBeUndefined();
        expect(
            fetchMock.mock.calls.find((c) =>
                String(c[0]).endsWith('/tables/calendar-move'),
            )![1].body.start,
        ).toBe('2026-06-16T09:00:00.000Z');
    });

    it('a calendar that resizes but does not move ignores Alt+arrows', async () => {
        const w = mountIt({
            calendar: makeCalendar({ moveable: false, resizable: true }),
        });

        await blockOf(w, 1).trigger('keydown', {
            key: 'ArrowRight',
            altKey: true,
        });
        await flush(w);

        expect(fetchMock).not.toHaveBeenCalled();
    });
});
