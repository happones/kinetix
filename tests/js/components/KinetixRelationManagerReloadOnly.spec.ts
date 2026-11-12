import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: { kinetix_config: { route_prefix: '_kinetix' } },
    }),
    router: { get: vi.fn(), visit: vi.fn(), reload: vi.fn(), replace: vi.fn() },
    usePoll: () => ({ start: vi.fn(), stop: vi.fn() }),
    Link: {
        name: 'Link',
        template: '<a :href="href"><slot /></a>',
        props: ['href'],
    },
}));

import KinetixRelationManager from '@/components/KinetixRelationManager.vue';
import KinetixTable from '@/components/KinetixTable.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: { en: { kinetix: {} } },
});

const mountManager = (props: Record<string, unknown> = {}) =>
    mount(KinetixRelationManager, {
        props: {
            manager: {
                title: 'Tags',
                relationship: 'tags',
                table: { records: [] },
                badge: null,
                badgeColor: null,
            },
            ...props,
        } as any,
        global: { plugins: [i18n], stubs: { KinetixTable: true } },
    });

describe('KinetixRelationManager reloadOnly', () => {
    it('forwards reloadOnly to its table', () => {
        const wrapper = mountManager({ reloadOnly: ['record'] });

        expect(wrapper.findComponent(KinetixTable).props('reloadOnly')).toEqual(
            ['record'],
        );
    });

    it('leaves the table on full reloads when none is named', () => {
        const wrapper = mountManager();

        expect(
            wrapper.findComponent(KinetixTable).props('reloadOnly'),
        ).toBeUndefined();
    });
});
