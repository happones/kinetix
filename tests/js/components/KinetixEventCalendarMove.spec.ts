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

import KinetixEventCalendar from '@/components/KinetixEventCalendar.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: {} } },
});

// June 2026 fixture; pin "today" so the grid is deterministic.
const makeCalendar = (model: string | null) => ({
    heading: null,
    timezone: 'UTC',
    model,
    events: [
        {
            id: 1,
            title: 'Launch',
            start: '2026-06-15T09:00:00+00:00',
            end: '2026-06-15T10:30:00+00:00',
            allDay: false,
            color: '#22c55e',
            url: null,
            description: null,
            actions: [],
        },
    ],
});

const mountIt = (props: Record<string, unknown> = {}) =>
    mount(KinetixEventCalendar, {
        props: {
            calendar: makeCalendar('signed-descriptor'),
            locale: 'en-US',
            ...props,
        },
        global: { plugins: [i18n] },
        attachTo: document.body,
    });

const chipOf = (w: ReturnType<typeof mountIt>, title: string) =>
    w.findAll('button').find((b) => b.text() === title);

describe('KinetixEventCalendar drag-and-drop moves', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-06-15T12:00:00Z'));
        fetchMock.mockClear().mockResolvedValue({ status: 'success' });
        toastError.mockClear();
        reloadMock.mockClear();
    });

    afterEach(() => {
        vi.useRealTimers();
        document.body.innerHTML = '';
    });

    it('read-only calendars (no descriptor) render nothing draggable', () => {
        const w = mountIt({ calendar: makeCalendar(null) });

        expect(w.find('[draggable="true"]').exists()).toBe(false);
        expect(w.find('[data-calendar-drop]').exists()).toBe(false);
    });

    it('dragging an event to another day keeps its time and posts the move', async () => {
        const w = mountIt();

        expect(chipOf(w, 'Launch')?.attributes('draggable')).toBe('true');

        await chipOf(w, 'Launch')!.trigger('dragstart');
        await w.get('[data-calendar-drop="day:2026-06-18"]').trigger('drop');
        await Promise.resolve();

        const call = fetchMock.mock.calls.find((c) =>
            String(c[0]).endsWith('/tables/calendar-move'),
        );
        expect(call).toBeTruthy();
        expect(call![1].body).toMatchObject({
            model: 'signed-descriptor',
            recordId: 1,
            start: '2026-06-18T09:00:00.000Z',
        });
    });

    it('dropping on a week-view hour slot snaps the start to that slot', async () => {
        const w = mountIt({ views: ['week'], view: 'week' });

        await chipOf(w, 'Launch')!.trigger('dragstart');
        await w
            .get('[data-calendar-drop="slot:2026-06-18:14"]')
            .trigger('drop');
        await Promise.resolve();

        const call = fetchMock.mock.calls.find((c) =>
            String(c[0]).endsWith('/tables/calendar-move'),
        );
        expect(call).toBeTruthy();
        expect(call![1].body).toMatchObject({
            recordId: 1,
            start: '2026-06-18T14:00:00.000Z',
        });
    });

    it('Alt+ArrowRight is the keyboard alternative (one day later)', async () => {
        const w = mountIt();

        await chipOf(w, 'Launch')!.trigger('keydown', {
            key: 'ArrowRight',
            altKey: true,
        });
        await Promise.resolve();

        const call = fetchMock.mock.calls.find((c) =>
            String(c[0]).endsWith('/tables/calendar-move'),
        );
        expect(call).toBeTruthy();
        expect(call![1].body).toMatchObject({
            recordId: 1,
            start: '2026-06-16T09:00:00.000Z',
        });
    });

    it('an arrow without Alt never moves anything', async () => {
        const w = mountIt();

        await chipOf(w, 'Launch')!.trigger('keydown', { key: 'ArrowRight' });
        await Promise.resolve();

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('reverts the optimistic move and toasts when the server rejects it', async () => {
        fetchMock.mockRejectedValueOnce(new Error('forbidden'));
        const w = mountIt();

        await chipOf(w, 'Launch')!.trigger('dragstart');
        await w.get('[data-calendar-drop="day:2026-06-18"]').trigger('drop');
        await Promise.resolve();
        await Promise.resolve();
        await w.vm.$nextTick();

        expect(toastError).toHaveBeenCalledTimes(1);
        expect(reloadMock).not.toHaveBeenCalled();

        // The chip is back in its original cell.
        const originCell = w.get('[data-calendar-drop="day:2026-06-15"]');
        expect(originCell.text()).toContain('Launch');
    });
});
