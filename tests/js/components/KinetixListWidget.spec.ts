import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import KinetixListWidget from '@/components/KinetixListWidget.vue';

const widget = {
    id: 'w1',
    type: 'list' as const,
    title: 'Stock alerts',
    columnSpan: 4,
    sort: 0,
    data: {
        icon: 'alert-triangle',
        actionLabel: 'View inventory',
        actionUrl: '/inventory',
        emptyState: 'All good',
        items: [
            {
                title: 'Jugo Del Valle 1L',
                subtitle: 'Out of stock',
                icon: 'alert-triangle',
                iconColor: 'danger',
                value: null,
                badge: '0',
                badgeColor: 'danger',
                progress: null,
                url: null,
            },
            {
                title: 'Sabritas 45g',
                subtitle: null,
                icon: null,
                iconColor: null,
                value: '3',
                badge: null,
                badgeColor: null,
                progress: 20,
                url: '/products/2',
            },
        ],
    },
};

describe('KinetixListWidget', () => {
    it('renders the title, items and footer action', () => {
        const w = mount(KinetixListWidget, { props: { widget } });

        expect(w.text()).toContain('Stock alerts');
        expect(w.text()).toContain('Jugo Del Valle 1L');
        expect(w.text()).toContain('Out of stock');
        expect(w.text()).toContain('Sabritas 45g');
        expect(w.text()).toContain('3');

        const action = w.find('a[href="/inventory"]');
        expect(action.exists()).toBe(true);
        expect(action.text()).toContain('View inventory');
    });

    it('renders a progress bar and links item rows with a url', () => {
        const w = mount(KinetixListWidget, { props: { widget } });

        // The second item has a progress bar (inline width style).
        expect(w.html()).toContain('width: 20%');
        // …and is rendered as a link because it has a url.
        expect(w.find('a[href="/products/2"]').exists()).toBe(true);
    });

    it('shows the empty state with no items', () => {
        const empty = { ...widget, data: { ...widget.data, items: [] } };
        const w = mount(KinetixListWidget, { props: { widget: empty } });
        expect(w.text()).toContain('All good');
    });
});
