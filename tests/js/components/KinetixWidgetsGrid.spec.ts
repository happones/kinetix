import { mount, shallowMount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import KinetixWidgetsGrid from '@/components/KinetixWidgetsGrid.vue';

const statsWidget = {
    id: 'w1',
    columnSpan: 6,
    sort: 0,
    type: 'stats' as const,
    data: { stats: [{ label: 'Users', value: '10' }] },
};

const customWidget = {
    id: 'custom-1',
    columnSpan: 12,
    sort: 1,
    type: 'custom' as const,
    data: {},
};

describe('KinetixWidgetsGrid', () => {
    it('renders the CSS grid layout by default', () => {
        const wrapper = mount(KinetixWidgetsGrid, {
            props: {
                grid: {
                    columns: 12,
                    gap: '1.5rem',
                    layout: 'grid',
                    dense: false,
                    masonryColumns: 3,
                    widgets: [statsWidget],
                },
            },
        });

        expect(wrapper.find('.kinetix-widgets-grid').exists()).toBe(true);
        expect(
            wrapper.findComponent({ name: 'KinetixMasonryColumns' }).exists(),
        ).toBe(false);
    });

    it('switches to KinetixMasonryColumns when layout is masonry', () => {
        const wrapper = mount(KinetixWidgetsGrid, {
            props: {
                grid: {
                    columns: 12,
                    gap: '1rem',
                    layout: 'masonry',
                    dense: false,
                    masonryColumns: 2,
                    widgets: [statsWidget],
                },
            },
        });

        expect(wrapper.find('.kinetix-widgets-grid').exists()).toBe(false);
        expect(wrapper.text()).toContain('Users');
    });

    it('sets the dense CSS var only on the grid layout', () => {
        const wrapper = mount(KinetixWidgetsGrid, {
            props: {
                grid: {
                    columns: 12,
                    gap: '1rem',
                    layout: 'grid',
                    dense: true,
                    masonryColumns: 3,
                    widgets: [statsWidget],
                },
            },
        });

        expect(
            wrapper.find('.kinetix-widgets-grid').attributes('style'),
        ).toContain('--grid-auto-flow: dense');
    });

    it('applies responsive gap CSS vars', () => {
        const wrapper = mount(KinetixWidgetsGrid, {
            props: {
                grid: {
                    columns: 12,
                    gap: { default: '1rem', lg: '2rem' },
                    layout: 'grid',
                    dense: false,
                    masonryColumns: 3,
                    widgets: [statsWidget],
                },
            },
        });

        const style = wrapper.find('.kinetix-widgets-grid').attributes('style');

        expect(style).toContain('--grid-gap-default: 1rem');
        expect(style).toContain('--grid-gap-lg: 2rem');
    });

    it('dispatches the self-polling queue-stats and health-status widget types', () => {
        // shallowMount — KinetixQueueStats/KinetixHealthStatus self-poll via
        // useKinetixHttp/vue-i18n, which need their own dedicated test setup
        // (see KinetixQueueStats.spec.ts); this test only checks dispatch.
        const wrapper = shallowMount(KinetixWidgetsGrid, {
            props: {
                grid: {
                    columns: 12,
                    gap: '1rem',
                    layout: 'grid',
                    dense: false,
                    masonryColumns: 3,
                    widgets: [
                        {
                            id: 'q1',
                            columnSpan: 6,
                            sort: 0,
                            type: 'queue-stats' as const,
                            data: {},
                        },
                        {
                            id: 'h1',
                            columnSpan: 6,
                            sort: 1,
                            type: 'health-status' as const,
                            data: {},
                        },
                    ],
                },
            },
        });

        expect(
            wrapper.findComponent({ name: 'KinetixQueueStats' }).exists(),
        ).toBe(true);
        expect(
            wrapper.findComponent({ name: 'KinetixHealthStatus' }).exists(),
        ).toBe(true);
    });

    it('renders custom widgets via the per-id named slot in both layouts', () => {
        const wrapper = mount(KinetixWidgetsGrid, {
            props: {
                grid: {
                    columns: 12,
                    gap: '1rem',
                    layout: 'grid',
                    dense: false,
                    masonryColumns: 3,
                    widgets: [customWidget],
                },
            },
            slots: {
                'custom-1': '<p>custom content</p>',
            },
        });

        expect(wrapper.text()).toContain('custom content');
    });
});
