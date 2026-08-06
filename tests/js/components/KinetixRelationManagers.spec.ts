import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { describe, expect, it } from 'vitest';
import KinetixRelationManagers from '@/components/KinetixRelationManagers.vue';
import type { KinetixRelationManagerData } from '@/types/kinetix';
import { i18n } from './i18n';

/** The real KinetixTable is heavy — stub it with its query prefix as text. */
const TableStub = defineComponent({
    props: { table: { type: Object, required: true } },
    setup(props) {
        return () =>
            h(
                'div',
                { 'data-test': 'table' },
                String(props.table.queryPrefix ?? ''),
            );
    },
});

const manager = (
    relationship: string,
    overrides: Partial<KinetixRelationManagerData> = {},
): KinetixRelationManagerData =>
    ({
        title: relationship.charAt(0).toUpperCase() + relationship.slice(1),
        relationship,
        table: { queryPrefix: `${relationship}_` },
        ...overrides,
    }) as KinetixRelationManagerData;

const mountManagers = (
    managers: KinetixRelationManagerData[],
    props: Record<string, unknown> = {},
) =>
    mount(KinetixRelationManagers, {
        props: { managers, ...props },
        global: {
            plugins: [i18n],
            stubs: { KinetixTable: TableStub },
        },
    });

describe('KinetixRelationManagers', () => {
    it('renders a single manager as a plain section (no tabs)', () => {
        const wrapper = mountManagers([manager('posts')]);

        expect(wrapper.find('[role="tablist"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Posts');
        expect(wrapper.get('[data-test="table"]').text()).toBe('posts_');
    });

    it('auto-tabs multiple managers and renders only the active table', async () => {
        const wrapper = mountManagers([
            manager('posts', { badge: 3 }),
            manager('comments', { badge: 12, badgeColor: 'primary' }),
            manager('tags'),
        ]);

        const tabs = wrapper.findAll('[role="tab"]');
        expect(tabs).toHaveLength(3);

        // Badges render on the tabs.
        expect(tabs[0].text()).toContain('3');
        expect(tabs[1].text()).toContain('12');

        // Only the first (active) manager's table is mounted.
        expect(wrapper.findAll('[data-test="table"]')).toHaveLength(1);
        expect(wrapper.get('[data-test="table"]').text()).toBe('posts_');

        await tabs[1].trigger('click');

        expect(wrapper.get('[data-test="table"]').text()).toBe('comments_');
        expect(tabs[1].attributes('aria-selected')).toBe('true');
    });

    it('stacks every manager when tabs are disabled', () => {
        const wrapper = mountManagers([manager('posts'), manager('comments')], {
            tabs: false,
        });

        expect(wrapper.find('[role="tablist"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-test="table"]')).toHaveLength(2);
    });

    it('renders nothing for an empty manager list', () => {
        const wrapper = mountManagers([]);

        expect(wrapper.find('section').exists()).toBe(false);
        expect(wrapper.find('[role="tablist"]').exists()).toBe(false);
    });
});
