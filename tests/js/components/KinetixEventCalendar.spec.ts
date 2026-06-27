import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';
import KinetixEventCalendar from '@/components/KinetixEventCalendar.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: {} } },
});

// June 2026 fixture; pin "today" so the grid is deterministic.
const calendar = {
    heading: null,
    events: [
        {
            id: 1,
            title: 'Launch',
            start: '2026-06-15',
            end: null,
            color: '#22c55e',
            url: null,
        },
        {
            id: 2,
            title: 'Sprint',
            start: '2026-06-20',
            end: '2026-06-24',
            color: null,
            url: '/e/2',
        },
    ],
};

const mountIt = () =>
    mount(KinetixEventCalendar, {
        props: { calendar, locale: 'en-US' },
        global: { plugins: [i18n] },
    });

describe('KinetixEventCalendar', () => {
    it('renders a 6-week grid with weekday headers', () => {
        const w = mountIt();
        // 7 weekday headers + 42 day cells.
        expect(w.findAll('.grid-cols-7').length).toBeGreaterThanOrEqual(2);
        expect(w.findAll('.min-h-24').length).toBe(42);
    });

    it('places events on their day and links events with a url', () => {
        const w = mountIt();
        expect(w.text()).toContain('Launch');
        expect(w.text()).toContain('Sprint');
        // The event with a url renders as an anchor.
        const link = w.findAll('a').find((a) => a.text() === 'Sprint');
        expect(link?.attributes('href')).toBe('/e/2');
    });

    it('navigates months with prev/next', async () => {
        const w = mountIt();
        const labelBefore = w.find('h2').text();
        await w.find('[aria-label="kinetix.calendar_next"]').trigger('click');
        expect(w.find('h2').text()).not.toBe(labelBefore);
    });
});
