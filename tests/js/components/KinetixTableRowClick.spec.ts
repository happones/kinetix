import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { createI18n } from 'vue-i18n';

const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: { visit: (...args: unknown[]) => visit(...args), reload: vi.fn() },
    usePage: () => ({ props: { errors: {} } }),
    usePoll: vi.fn(),
}));

const fetchMock = vi.fn(async () => ({
    infolist: { entries: [], data: {} },
}));

vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...(args as [])),
}));

import KinetixTable from '@/components/KinetixTable.vue';

/**
 * A row is a click target when the server hands it a `recordUrl` (navigate) or
 * a `recordAction` (run one of its own actions, e.g. the modal `view`). The
 * contract these specs pin: every nested control — the "⋯" actions trigger
 * above all — keeps its own behaviour and never doubles as a row click, and the
 * row stays keyboard-operable.
 */
const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            kinetix: {
                more_actions: 'More actions',
                close: 'Close',
                no_records_found: 'No records',
            },
        },
    },
});

const action = (overrides: Record<string, unknown>) => ({
    label: 'Action',
    icon: null,
    color: 'gray',
    shouldOpenInNewTab: false,
    viewType: 'button',
    shouldClose: false,
    shouldMarkAsRead: false,
    shouldMarkAsUnread: false,
    requiresConfirmation: false,
    type: 'action',
    ...overrides,
});

const viewModal = action({ name: 'view', label: 'View', modal: 'view' });
const editModal = action({ name: 'edit', label: 'Edit', modal: 'edit' });
const group = action({
    name: 'action-group',
    label: '',
    type: 'group',
    actions: [viewModal, editModal],
});

const record = (overrides: Record<string, unknown> = {}) => ({
    id: 1,
    values: { name: 'Ada' },
    icons: {},
    iconColors: {},
    badgeColors: {},
    descriptions: {},
    progress: {},
    progressColors: {},
    viewProps: {},
    recordUrl: null,
    recordUrlInNewTab: false,
    recordAction: null,
    actions: [group],
    ...overrides,
});

const table = (overrides: Record<string, unknown> = {}) => ({
    heading: null,
    description: null,
    poll: null,
    isStriped: false,
    model: 'App\\Models\\Post',
    columns: [
        {
            name: 'name',
            label: 'Name',
            type: 'text',
            isSearchable: false,
            isSortable: false,
            alignment: 'left',
        },
    ],
    filters: [],
    recordActions: [group],
    toolbarActions: [],
    bulkActions: [],
    footerActions: [],
    records: [record()],
    isPaginated: false,
    paginationPageOptions: [10],
    pagination: null,
    state: { search: '', sort: '', direction: 'asc', filters: {}, perPage: 10 },
    queryPrefix: '',
    recordModals: {
        enabled: true,
        token: 'signed-token',
        source: 'server',
        createForm: null,
    },
    ...overrides,
});

const mountTable = (data: Record<string, unknown>) =>
    mount(KinetixTable, {
        attachTo: document.body,
        props: { table: data as any },
        global: { plugins: [i18n] },
    });

afterEach(() => {
    visit.mockReset();
    fetchMock.mockClear();
    document.body.innerHTML = '';
});

describe('KinetixTable — clickable rows', () => {
    it('navigates to recordUrl when the row body is clicked', async () => {
        const w = mountTable(
            table({ records: [record({ recordUrl: '/posts/1' })] }),
        );
        const row = w.find('tbody tr');

        expect(row.classes()).toContain('cursor-pointer');
        expect(row.attributes('tabindex')).toBe('0');

        await row.find('td').trigger('click');

        expect(visit).toHaveBeenCalledWith('/posts/1');
        w.unmount();
    });

    it('opens recordUrl in a new tab on ctrl/meta-click or when the table asks for it', async () => {
        const open = vi.spyOn(window, 'open').mockImplementation(() => null);
        const w = mountTable(
            table({ records: [record({ recordUrl: '/posts/1' })] }),
        );

        await w.find('tbody tr td').trigger('click', { ctrlKey: true });

        expect(open).toHaveBeenCalledWith(
            '/posts/1',
            '_blank',
            'noopener,noreferrer',
        );
        expect(visit).not.toHaveBeenCalled();

        await w.setProps({
            table: table({
                records: [
                    record({ recordUrl: '/posts/1', recordUrlInNewTab: true }),
                ],
            }) as any,
        });
        await w.find('tbody tr td').trigger('click');

        expect(open).toHaveBeenCalledTimes(2);
        expect(visit).not.toHaveBeenCalled();

        open.mockRestore();
        w.unmount();
    });

    it('runs the row action through the same modal path as its own button', async () => {
        const w = mountTable(
            table({ records: [record({ recordAction: 'view' })] }),
        );
        const row = w.find('tbody tr');

        expect(row.classes()).toContain('cursor-pointer');

        await row.find('td').trigger('click');
        await nextTick();
        await nextTick();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect((fetchMock.mock.calls[0] as unknown[])[1]).toMatchObject({
            body: { mode: 'view', id: 1 },
        });
        expect(document.body.querySelector('[role="dialog"]')).not.toBeNull();
        expect(visit).not.toHaveBeenCalled();
        w.unmount();
    });

    it('leaves the "⋯" actions trigger and its cell to themselves', async () => {
        const w = mountTable(
            table({
                records: [
                    record({ recordUrl: '/posts/1', recordAction: null }),
                ],
            }),
        );

        const trigger = w.find('tbody tr button[aria-label="More actions"]');
        expect(trigger.exists()).toBe(true);

        await trigger.trigger('click');
        await trigger.trigger('pointerdown', { button: 0, ctrlKey: false });
        await nextTick();

        // The actions cell swallows the click before it reaches the row.
        const actionsCell = w.findAll('tbody tr td').at(-1)!;
        await actionsCell.trigger('click');

        expect(visit).not.toHaveBeenCalled();
        expect(fetchMock).not.toHaveBeenCalled();
        w.unmount();
    });

    it('activates on Enter from the row itself, not from a nested control', async () => {
        const w = mountTable(
            table({ records: [record({ recordUrl: '/posts/1' })] }),
        );
        const row = w.find('tbody tr');

        await row
            .find('button[aria-label="More actions"]')
            .trigger('keydown', { key: 'Enter' });
        expect(visit).not.toHaveBeenCalled();

        await row.trigger('keydown', { key: 'Enter' });
        expect(visit).toHaveBeenCalledWith('/posts/1');
        w.unmount();
    });

    it('renders an inert row when the server hands it no target', async () => {
        const w = mountTable(table());
        const row = w.find('tbody tr');

        expect(row.classes()).not.toContain('cursor-pointer');
        expect(row.attributes('tabindex')).toBeUndefined();

        await row.find('td').trigger('click');
        await row.trigger('keydown', { key: 'Enter' });

        expect(visit).not.toHaveBeenCalled();
        expect(fetchMock).not.toHaveBeenCalled();
        w.unmount();
    });

    it('ignores a recordAction the row does not actually carry', async () => {
        const w = mountTable(
            table({ records: [record({ recordAction: 'archive' })] }),
        );
        const row = w.find('tbody tr');

        expect(row.classes()).not.toContain('cursor-pointer');

        await row.find('td').trigger('click');

        expect(fetchMock).not.toHaveBeenCalled();
        w.unmount();
    });
});
