import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import KinetixProgressWidget from '@/components/KinetixProgressWidget.vue';

const barWidget = {
    id: 'p1',
    type: 'progress' as const,
    title: 'Monthly goal',
    columnSpan: 4,
    sort: 0,
    data: {
        value: 7200,
        target: 10000,
        percent: 72,
        display: '$7,200',
        caption: 'of $10,000',
        color: 'success',
        ring: false,
    },
};

const ringWidget = {
    ...barWidget,
    id: 'p2',
    data: { ...barWidget.data, ring: true },
};

describe('KinetixProgressWidget', () => {
    it('renders the bar variant with display, caption and fill width', () => {
        const w = mount(KinetixProgressWidget, {
            props: { widget: barWidget },
        });

        expect(w.text()).toContain('Monthly goal');
        expect(w.text()).toContain('$7,200');
        expect(w.text()).toContain('of $10,000');
        expect(w.html()).toContain('width: 72%');
        expect(w.html()).toContain('bg-success');
        // Bar variant has no <svg> ring.
        expect(w.find('svg').exists()).toBe(false);
    });

    it('renders the ring variant with an svg and the percentage in the center', () => {
        const w = mount(KinetixProgressWidget, {
            props: { widget: ringWidget },
        });

        expect(w.find('svg').exists()).toBe(true);
        expect(w.text()).toContain('$7,200');
        expect(w.text()).toContain('of $10,000');
    });

    it('clamps an over-target percent to 100', () => {
        const w = mount(KinetixProgressWidget, {
            props: {
                widget: {
                    ...barWidget,
                    data: { ...barWidget.data, percent: 140 },
                },
            },
        });

        expect(w.html()).toContain('width: 100%');
    });
});
