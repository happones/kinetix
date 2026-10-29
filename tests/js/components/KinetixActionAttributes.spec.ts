import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: { kinetix_config: { route_prefix: '_kinetix' } },
    }),
    router: { get: vi.fn(), visit: vi.fn(), reload: vi.fn() },
}));

import KinetixActionDropdown from '@/components/KinetixActionDropdown.vue';
import KinetixPageFooter from '@/components/KinetixPageFooter.vue';
import KinetixPageHeader from '@/components/KinetixPageHeader.vue';
import KinetixTableBulkBar from '@/components/Table/KinetixTableBulkBar.vue';
import KinetixTableToolbar from '@/components/Table/KinetixTableToolbar.vue';
import { actionAttributes } from '@/composables/kinetixActionAttributes';

/**
 * Every rendered action carries `data-action="<name>"` — the hook a tour, an
 * E2E test or analytics can rely on without reaching into markup — plus the
 * server's `extraAttributes()`.
 */
const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: {} } },
});

const action = (over: Record<string, unknown> = {}) => ({
    name: 'create',
    label: 'New employee',
    viewType: 'button',
    shouldOpenInNewTab: false,
    color: 'primary',
    shouldClose: false,
    shouldMarkAsRead: false,
    shouldMarkAsUnread: false,
    extraAttributes: { 'data-tour': 'create' },
    ...over,
});

const global = { plugins: [i18n], stubs: { KinetixSavedViews: true } };

describe('actionAttributes', () => {
    it('names the action and keeps the extra attributes', () => {
        expect(actionAttributes(action())).toEqual({
            'data-tour': 'create',
            'data-action': 'create',
        });
    });

    it('drops event handlers and keeps data-action as the name', () => {
        expect(
            actionAttributes({
                name: 'create',
                extraAttributes: {
                    onclick: 'alert(1)',
                    OnFocus: 'alert(1)',
                    'data-action': 'other',
                },
            }),
        ).toEqual({ 'data-action': 'create' });
    });
});

describe('rendered actions carry data-action', () => {
    it('in the page header and footer, as buttons and as links', () => {
        const actions = [
            action(),
            action({ name: 'import', label: 'Import', viewType: 'link' }),
        ];

        for (const bar of [KinetixPageHeader, KinetixPageFooter]) {
            const w = mount(bar, { props: { actions }, global });

            const create = w.get('[data-action="create"]');
            expect(create.element.tagName).toBe('BUTTON');
            expect(create.attributes('data-tour')).toBe('create');
            expect(w.get('[data-action="import"]').element.tagName).toBe('A');
        }
    });

    it('in the table toolbar', () => {
        const w = mount(KinetixTableToolbar, {
            props: {
                table: {
                    heading: 'Employees',
                    description: null,
                    columns: [],
                    filters: [],
                    toolbarActions: [action()],
                    savedViewsKey: null,
                } as never,
                searchQuery: '',
                activeFilters: {},
                currentViewState: {},
                isColumnVisible: () => true,
            },
            global,
        });

        expect(w.get('[data-action="create"]').text()).toContain(
            'New employee',
        );
    });

    it('in the bulk bar', () => {
        const w = mount(KinetixTableBulkBar, {
            props: {
                bulkActions: [action({ name: 'delete', label: 'Delete' })],
                selectionCount: 2,
            } as never,
            global,
        });

        expect(w.get('[data-action="delete"]').text()).toContain('Delete');
    });

    it('on an action group trigger', () => {
        const w = mount(KinetixActionDropdown, {
            props: {
                group: action({
                    name: 'more',
                    type: 'group',
                    label: 'More',
                    actions: [action()],
                }) as never,
            },
            global,
        });

        expect(w.find('[data-action="more"]').exists()).toBe(true);
    });
});
