import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';

import KinetixRatingWidget from '@/components/KinetixRatingWidget.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: {
        en: {
            kinetix: {
                rating_out_of: 'out of {max}',
                rating_reviews: 'Based on {total} reviews',
            },
        },
    },
});

const widget = {
    id: 'r1',
    type: 'rating' as const,
    title: 'Customer reviews',
    columnSpan: 6,
    sort: 0,
    data: {
        average: 4.5,
        total: 5500,
        max: 5,
        breakdown: [
            { level: 5, count: 4000, pct: 100 },
            { level: 4, count: 2100, pct: 53 },
            { level: 3, count: 800, pct: 20 },
            { level: 2, count: 631, pct: 16 },
            { level: 1, count: 344, pct: 9 },
        ],
    },
};

describe('KinetixRatingWidget', () => {
    it('renders the average, breakdown rows and total', () => {
        const w = mount(KinetixRatingWidget, {
            props: { widget },
            global: { plugins: [i18n] },
        });

        expect(w.text()).toContain('4.5');
        expect(w.text()).toContain('Customer reviews');
        expect(w.text()).toContain('Based on 5,500 reviews');
        // 5 breakdown rows + the average stars; counts formatted.
        expect(w.text()).toContain('4,000');
        expect(w.html()).toContain('width: 53%');
    });
});
