import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: { kinetix_config: { route_prefix: '_kinetix' } },
    }),
    router: { get: vi.fn(), visit: vi.fn(), reload: vi.fn() },
    usePoll: () => ({ start: vi.fn(), stop: vi.fn() }),
    Link: {
        name: 'Link',
        template: '<a :href="href"><slot /></a>',
        props: ['href'],
    },
}));

import KinetixTable from '@/components/KinetixTable.vue';

/**
 * An action whose `->icon('…')` name Kinetix cannot resolve must still be
 * visible.
 *
 * The regression: the renderers guarded on the NAME (`v-if="action.icon"`), so
 * an unknown name rendered no icon — and on an `->iconButton()` action no label
 * either, because an icon button hides it. The button was there, sized for an
 * icon, containing nothing: present, focusable, clickable, invisible. It took
 * weeks to notice on a real screen.
 */
const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: { en: { kinetix: { results_count: '{count} results' } } },
});

const tableWith = (recordActions: Array<Record<string, unknown>>) =>
    ({
        heading: null,
        description: null,
        poll: null,
        isStriped: false,
        model: 'token',
        columns: [
            {
                name: 'name',
                label: 'Name',
                isSearchable: false,
                isSortable: false,
                alignment: 'left',
                isToggleable: false,
                isToggledHiddenByDefault: false,
                type: 'text',
            },
        ],
        filters: [],
        recordActions,
        toolbarActions: [],
        bulkActions: [],
        footerActions: [],
        records: [
            {
                key: 1,
                values: { name: 'Widget' },
                // The row renders ITS OWN authorized actions; the table-level
                // list only decides whether the actions column exists.
                actions: recordActions,
            },
        ],
        isPaginated: false,
        paginationPageOptions: [10],
        pagination: null,
        state: {
            search: '',
            sort: '',
            direction: 'asc',
            filters: {},
            perPage: 10,
        },
        queryPrefix: '',
        summaries: {},
        hasSummaries: false,
    }) as any;

const action = (over: Record<string, unknown> = {}) => ({
    name: 'adjust',
    label: 'Adjust',
    viewType: 'button',
    shouldOpenInNewTab: false,
    color: null,
    shouldClose: false,
    shouldMarkAsRead: false,
    shouldMarkAsUnread: false,
    ...over,
});

const mountTable = (recordActions: Array<Record<string, unknown>>) =>
    mount(KinetixTable, {
        props: { table: tableWith(recordActions) },
        global: {
            plugins: [i18n],
            // The cells are irrelevant here; the row's ACTIONS are the subject.
            stubs: {
                KinetixTableHead: true,
                KinetixTableCell: true,
                KinetixTablePagination: true,
            },
        },
    });

const actionButton = (w: ReturnType<typeof mountTable>) =>
    w.find('tbody button');

describe('an action whose icon cannot be resolved', () => {
    it('falls back to its LABEL on an icon button, instead of painting nothing', () => {
        const w = mountTable([
            action({ icon: 'definitely-not-an-icon', isIconButton: true }),
        ]);

        const button = actionButton(w);
        expect(button.text()).toContain('Adjust');
        expect(button.find('svg').exists()).toBe(false);
        // …and it is no longer sized for an icon it does not have.
        expect(button.classes()).not.toContain('size-8');
        // A label is showing, so it must not ALSO be the accessible name twice.
        expect(button.attributes('aria-label')).toBeUndefined();
    });

    it('still renders an icon-only button when the icon does resolve', () => {
        const w = mountTable([action({ icon: 'trash-2', isIconButton: true })]);

        const button = actionButton(w);
        expect(button.find('svg').exists()).toBe(true);
        expect(button.text()).toBe('');
        // The label survives as the accessible name.
        expect(button.attributes('aria-label')).toBe('Adjust');
        expect(button.classes()).toContain('size-8');
    });

    it('drops only the icon on a normal labelled action', () => {
        const w = mountTable([action({ icon: 'definitely-not-an-icon' })]);

        const button = actionButton(w);
        expect(button.text()).toContain('Adjust');
        expect(button.find('svg').exists()).toBe(false);
    });

    it('renders a resolvable icon alongside the label', () => {
        const w = mountTable([action({ icon: 'trash-2' })]);

        const button = actionButton(w);
        expect(button.text()).toContain('Adjust');
        expect(button.find('svg').exists()).toBe(true);
    });
});
