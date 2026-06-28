import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import WidgetHeaderActions from '@/components/widgets/WidgetHeaderActions.vue';

describe('WidgetHeaderActions', () => {
    it('renders a link per action', () => {
        const w = mount(WidgetHeaderActions, {
            props: {
                actions: [
                    { label: 'Export', url: '/export', icon: 'download' },
                    { label: 'View all', url: '/all', icon: null },
                ],
            },
        });

        const links = w.findAll('a');
        expect(links).toHaveLength(2);
        expect(links[0].attributes('href')).toBe('/export');
        expect(links[0].text()).toContain('Export');
        expect(links[1].text()).toContain('View all');
    });

    it('renders nothing without actions', () => {
        const w = mount(WidgetHeaderActions, { props: { actions: [] } });
        expect(w.find('a').exists()).toBe(false);
    });
});
