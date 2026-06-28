import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import KinetixHeroWidget from '@/components/KinetixHeroWidget.vue';

const widget = {
    id: 'hero',
    type: 'hero' as const,
    title: 'Congratulations Toby! 🎉',
    columnSpan: 4,
    sort: 0,
    data: {
        subtitle: 'Best seller of the month',
        value: '$15,231.89',
        delta: '+65% from last month',
        deltaColor: 'success',
        actionLabel: 'View Sales',
        actionUrl: '/sales',
        gradient: true,
    },
};

describe('KinetixHeroWidget', () => {
    it('renders the greeting, value, delta and action', () => {
        const w = mount(KinetixHeroWidget, { props: { widget } });

        expect(w.text()).toContain('Congratulations Toby!');
        expect(w.text()).toContain('Best seller of the month');
        expect(w.text()).toContain('$15,231.89');
        expect(w.text()).toContain('+65% from last month');

        const action = w.find('a[href="/sales"]');
        expect(action.exists()).toBe(true);
        expect(action.text()).toContain('View Sales');
    });

    it('omits the action when no url is set', () => {
        const w = mount(KinetixHeroWidget, {
            props: {
                widget: {
                    ...widget,
                    data: { ...widget.data, actionUrl: null },
                },
            },
        });
        expect(w.find('a').exists()).toBe(false);
    });
});
