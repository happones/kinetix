import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { createI18n } from 'vue-i18n';
import KinetixEventCalendar from '@/components/KinetixEventCalendar.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: {
        en: {
            kinetix: {
                calendar_today: 'Today',
                calendar_prev: 'Previous month',
                calendar_next: 'Next month',
                calendar_prev_week: 'Previous week',
                calendar_next_week: 'Next week',
                calendar_prev_day: 'Previous day',
                calendar_next_day: 'Next day',
                calendar_more: '+{count} more',
                calendar_view_month: 'Month',
                calendar_view_week: 'Week',
                calendar_view_day: 'Day',
                calendar_all_day: 'All day',
                calendar_view_event: 'View details',
                close: 'Close',
            },
        },
    },
});

// June 2026 fixture; pin "today" so the grid is deterministic.
const calendar = {
    heading: null,
    timezone: 'UTC',
    events: [
        {
            id: 1,
            title: 'Launch',
            start: '2026-06-15T09:00:00+00:00',
            end: null,
            allDay: false,
            color: '#22c55e',
            url: null,
            description: null,
        },
        {
            id: 2,
            title: 'Sprint',
            start: '2026-06-20T00:00:00+00:00',
            end: '2026-06-24T00:00:00+00:00',
            allDay: true,
            color: null,
            url: '/e/2',
            description: 'Sprint planning week',
        },
    ],
};

const mountIt = (props: Record<string, unknown> = {}) =>
    mount(KinetixEventCalendar, {
        props: { calendar, locale: 'en-US', ...props },
        global: { plugins: [i18n] },
        attachTo: document.body,
    });

describe('KinetixEventCalendar', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-06-15T12:00:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
        document.body.innerHTML = '';
    });

    describe('month view (default, backward-compatible)', () => {
        it('renders a 6-week grid with weekday headers', () => {
            const w = mountIt();
            expect(w.findAll('.grid-cols-7').length).toBeGreaterThanOrEqual(2);
            expect(w.findAll('.min-h-24').length).toBe(42);
        });

        it('places events on their day and opens the details popup with a link when a url is set', async () => {
            const w = mountIt();
            expect(w.text()).toContain('Launch');
            expect(w.text()).toContain('Sprint');

            await w
                .findAll('button')
                .find((b) => b.text() === 'Sprint')
                ?.trigger('click');
            await nextTick();

            const link = document.body.querySelector('a');
            expect(link?.getAttribute('href')).toBe('/e/2');
        });

        it('navigates months with prev/next', async () => {
            const w = mountIt();
            const labelBefore = w.find('h2').text();
            await w.find('[aria-label="Previous month"]').trigger('click');
            expect(w.find('h2').text()).not.toBe(labelBefore);
        });

        it('does not show a view switcher when only one view is configured', () => {
            const w = mountIt();
            expect(w.find('[role="group"]').exists()).toBe(false);
        });

        it('opens on anchorDate instead of today when given', () => {
            const w = mountIt({ anchorDate: '2026-01-05' });
            expect(w.find('h2').text()).toBe('January 2026');
        });
    });

    describe('timezone correctness', () => {
        it('places a late-night UTC event on the next local day for an ahead timezone', () => {
            // 23:30 UTC on June 14 is 08:30 the next day (Jun 15) in Tokyo (UTC+9).
            const lateEvent = {
                heading: null,
                timezone: 'UTC',
                events: [
                    {
                        id: 9,
                        title: 'Late call',
                        start: '2026-06-14T23:30:00+00:00',
                        end: null,
                        allDay: false,
                        color: null,
                        url: null,
                        description: null,
                    },
                ],
            };

            const utcWrapper = mountIt({
                calendar: lateEvent,
                timezone: 'UTC',
            });
            const tokyoWrapper = mountIt({
                calendar: lateEvent,
                timezone: 'Asia/Tokyo',
            });

            const cellsWithEvent = (w: ReturnType<typeof mountIt>) =>
                w
                    .findAll('.min-h-24')
                    .filter((c) => c.text().includes('Late call'))
                    .map((c) => c.find('.mb-1').text());

            expect(cellsWithEvent(utcWrapper)).toEqual(['14']);
            expect(cellsWithEvent(tokyoWrapper)).toEqual(['15']);
        });

        it('falls back to calendar.timezone when no timezone prop is given', () => {
            const w = mountIt({
                calendar: { ...calendar, timezone: 'Asia/Tokyo' },
            });
            // No throw, renders normally — the fallback resolved to a valid IANA zone.
            expect(w.findAll('.min-h-24').length).toBe(42);
        });

        it('labels hour rows correctly and independent of the calendar timezone', () => {
            // Node/V8 caches Intl's default timezone at process startup, so a
            // runtime `process.env.TZ` mutation can't reliably simulate "the
            // viewing machine has a different local TZ" inside this test
            // process — that class of regression was instead caught (and is
            // fixed) via a real-browser Playwright check with a non-UTC
            // Chromium default timezone. Here we just lock in the correct,
            // stable label sequence, which must not shift with `timezone`.
            const utc = mountIt({
                views: ['day'],
                view: 'day',
                timezone: 'UTC',
            });
            const tokyo = mountIt({
                views: ['day'],
                view: 'day',
                timezone: 'Asia/Tokyo',
            });

            const labelsOf = (w: ReturnType<typeof mountIt>) =>
                w.findAll('.h-16.pr-2').map((el) => el.text());

            expect(labelsOf(utc)[9]).toBe('9 AM');
            expect(labelsOf(utc)).toEqual(labelsOf(tokyo));
        });
    });

    describe('views + switcher', () => {
        it('shows a switcher and defaults to the first configured view', () => {
            const w = mountIt({ views: ['month', 'week', 'day'] });
            expect(w.find('[role="group"]').exists()).toBe(true);
            expect(w.findAll('.min-h-24').length).toBe(42);
        });

        it('switches to week view and emits update:view', async () => {
            const w = mountIt({ views: ['month', 'week'] });
            const weekButton = w
                .findAll('[role="group"] button')
                .find((b) => b.text() === 'Week');
            await weekButton?.trigger('click');

            expect(w.emitted('update:view')?.[0]).toEqual(['week']);
            expect(w.findAll('.min-h-24').length).toBe(0);
        });

        it('renders 7 day columns in week view and 1 in day view', async () => {
            const week = mountIt({ views: ['week'], view: 'week' });
            expect(week.findAll('.border-l.border-border').length).toBe(7);

            const day = mountIt({ views: ['day'], view: 'day' });
            expect(day.findAll('.border-l.border-border').length).toBe(1);
        });

        it('places a timed event in the hourly grid, positioned by time', () => {
            // "today" is pinned to June 15 — the day view defaults to it,
            // which is exactly when the timed "Launch" event (09:00 UTC) falls.
            const w = mountIt({ views: ['day'], view: 'day' });
            const launchButton = w
                .findAll('button')
                .find((b) => b.text() === 'Launch');

            expect(launchButton).toBeTruthy();
            const style = (launchButton!.element as HTMLElement).style;
            expect(style.top).not.toBe('');
            expect(style.height).not.toBe('');
        });

        it('places an all-day/multi-day event in the banner, not the hourly grid', () => {
            const allDayCoversToday = {
                heading: null,
                timezone: 'UTC',
                events: [
                    {
                        id: 3,
                        title: 'Conference',
                        start: '2026-06-14T00:00:00+00:00',
                        end: '2026-06-17T00:00:00+00:00',
                        allDay: true,
                        color: null,
                        url: null,
                        description: null,
                    },
                ],
            };

            const w = mountIt({
                views: ['day'],
                view: 'day',
                calendar: allDayCoversToday,
            });

            const banner = w.find('.py-1');
            expect(banner.text()).toContain('Conference');

            const confButton = w
                .findAll('button')
                .find((b) => b.text() === 'Conference');
            const style = (confButton!.element as HTMLElement).style;
            // Banner events aren't positioned — no inline top/height.
            expect(style.top).toBe('');
            expect(style.height).toBe('');
        });
    });

    describe('event details popup', () => {
        it('opens a modal by default on event click', async () => {
            const w = mountIt();
            await w
                .findAll('button')
                .find((b) => b.text() === 'Launch')
                ?.trigger('click');
            await nextTick();

            expect(
                document.body.querySelector('[role="dialog"]'),
            ).not.toBeNull();
            expect(document.body.textContent).toContain('Launch');
        });

        it('emits event-click regardless of the built-in popup', async () => {
            const w = mountIt();
            await w
                .findAll('button')
                .find((b) => b.text() === 'Launch')
                ?.trigger('click');

            expect(w.emitted('event-click')?.[0]?.[0]).toMatchObject({
                id: 1,
                title: 'Launch',
            });
        });

        it('suppresses the built-in popup when showEventDetails is false', async () => {
            const w = mountIt({ showEventDetails: false });
            await w
                .findAll('button')
                .find((b) => b.text() === 'Launch')
                ?.trigger('click');
            await nextTick();

            expect(document.body.querySelector('[role="dialog"]')).toBeNull();
            expect(w.emitted('event-click')).toBeTruthy();
        });

        it('opens a sheet instead of a modal when eventDisplay is "sheet"', async () => {
            const w = mountIt({ eventDisplay: 'sheet', sheetSide: 'left' });
            await w
                .findAll('button')
                .find((b) => b.text() === 'Launch')
                ?.trigger('click');
            await nextTick();

            const panel = document.body.querySelector('.shadow-2xl');
            expect(panel?.className).toContain('left-0');
        });
    });

    describe('day-click', () => {
        it('emits day-click with the ISO date when an empty cell is clicked', async () => {
            const w = mountIt();
            const cell = w
                .findAll('.min-h-24')
                .find((c) => c.find('.mb-1').text() === '10');
            await cell?.trigger('click');

            expect(w.emitted('day-click')?.[0]?.[0]).toMatch(/^2026-06-10$/);
        });
    });
});
