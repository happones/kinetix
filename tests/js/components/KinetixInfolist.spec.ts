import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: { kinetix_config: { route_prefix: '_kinetix' } },
    }),
    router: { get: vi.fn(), visit: vi.fn(), reload: vi.fn() },
}));

import KinetixInfolist from '@/components/KinetixInfolist.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: { en: { kinetix: {} } },
});

const mountInfolist = (infolist: Record<string, unknown>) =>
    mount(KinetixInfolist, {
        props: { infolist: infolist as never },
        global: { plugins: [i18n] },
    });

describe('KinetixInfolist responsive grid', () => {
    it('renders section grids with per-breakpoint column vars (12-col system collapses below lg)', () => {
        const wrapper = mountInfolist({
            columns: 1,
            operation: 'view',
            schema: [
                {
                    type: 'section',
                    heading: 'Order',
                    columnSpan: 'full',
                    columns: 12,
                    schema: [
                        {
                            type: 'text',
                            name: 'customer',
                            label: 'Customer',
                            state: 'Ada',
                            columnSpan: 6,
                        },
                    ],
                },
            ],
        });

        const grids = wrapper.findAll('.kinetix-grid');
        // Root grid + the section's inner grid.
        expect(grids.length).toBeGreaterThanOrEqual(2);

        const sectionGrid = grids[1];
        expect(sectionGrid.attributes('style')).toContain('--kx-cols-base: 1');
        expect(sectionGrid.attributes('style')).toContain('--kx-cols-lg: 12');

        // The half-width entry clamps to a full row until the grid widens.
        const entry = sectionGrid.get('.kinetix-col');
        expect(entry.attributes('style')).toContain(
            '--kx-span-base: span 1 / span 1',
        );
        expect(entry.attributes('style')).toContain(
            '--kx-span-lg: span 6 / span 6',
        );
    });

    it('honors breakpoint maps on the root and full spans on entries', () => {
        const wrapper = mountInfolist({
            columns: { default: 1, sm: 2 },
            operation: 'view',
            schema: [
                {
                    type: 'text',
                    name: 'notes',
                    label: 'Notes',
                    state: 'hi',
                    columnSpan: 'full',
                },
            ],
        });

        const root = wrapper.get('.kinetix-grid');
        expect(root.attributes('style')).toContain('--kx-cols-sm: 2');

        const entry = wrapper.get('.kinetix-col');
        expect(entry.attributes('style')).toContain('--kx-span-base: 1 / -1');
    });
});
