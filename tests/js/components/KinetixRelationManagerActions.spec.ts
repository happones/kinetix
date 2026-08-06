import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: { kinetix_config: { route_prefix: 'acme/_kinetix' } },
    }),
    router: { get: vi.fn(), visit: vi.fn(), reload: vi.fn() },
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
    messages: {
        en: {
            kinetix: {
                detach: 'Detach',
                detached: 'Records detached.',
                detach_confirm: 'Detach this record?',
                confirm_heading: 'Are you sure?',
                confirm: 'Confirm',
                cancel: 'Cancel',
                more_actions: 'More actions',
                no_records: 'No results',
                results_count: '{count} results',
                action_failed: 'Action failed',
                attach: 'Attach',
                attached: 'Records attached.',
                attach_none_found: 'No records found',
                search_records: 'Search records',
            },
        },
    },
});

const col = (name: string) => ({
    name,
    label: name,
    isSearchable: false,
    isSortable: false,
    alignment: 'left',
    isToggleable: false,
    isToggledHiddenByDefault: false,
    type: 'text',
});

const detachAction = {
    type: 'action',
    name: 'detach',
    label: 'Detach',
    color: 'danger',
    requiresConfirmation: true,
    modalHeading: 'Detach this record?',
    dispatchEvent: 'detach-relation',
    dispatchData: { relationship: 'tags' },
};

const table = {
    heading: null,
    description: null,
    poll: null,
    isStriped: false,
    model: 'token',
    columns: [col('name')],
    filters: [],
    recordActions: [detachAction],
    toolbarActions: [],
    bulkActions: [],
    footerActions: [],
    records: [
        {
            id: 7,
            values: { name: 'php' },
            actions: [{ type: 'group', name: 'row', actions: [detachAction] }],
        },
    ],
    isPaginated: false,
    paginationPageOptions: [10],
    pagination: null,
    state: { search: '', sort: '', direction: 'asc', filters: {}, perPage: 10 },
    queryPrefix: 'tags_',
    summaries: {},
    hasSummaries: false,
} as any;

const tableStubs = {
    KinetixTableHead: true,
    KinetixTableCell: true,
    KinetixTablePagination: true,
    KinetixTableToolbar: true,
};

async function openDropdownAndSelect(wrapper: ReturnType<typeof mount>) {
    // The reka dropdown opens on pointerdown; content teleports to body.
    const trigger = wrapper.get('[aria-label="More actions"]');
    await trigger.trigger('pointerdown', { button: 0, pointerType: 'mouse' });
    await trigger.trigger('click');
    await nextTick();

    const item = document.body.querySelector('[role="menuitem"]');
    expect(item).not.toBeNull();

    item!.dispatchEvent(
        new Event('pointerup', { bubbles: true, cancelable: true }),
    );
    item!.dispatchEvent(
        new MouseEvent('click', { bubbles: true, cancelable: true }),
    );
    await nextTick();
}

async function confirmDialog() {
    // The table-level confirm modal teleports to body; confirm is the last button.
    await nextTick();
    const dialog = document.body.querySelector('[role="dialog"]');
    expect(dialog).not.toBeNull();

    const buttons = Array.from(dialog!.querySelectorAll('button'));
    const confirm = buttons.find((b) => b.textContent?.includes('Confirm'));
    expect(confirm).toBeTruthy();

    confirm!.dispatchEvent(
        new MouseEvent('click', { bubbles: true, cancelable: true }),
    );
    await nextTick();
}

describe('relation manager row actions carry the record end-to-end', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });

    it('a grouped DetachAction dispatches its event WITH the clicked record after confirm', async () => {
        const detail = vi.fn();
        window.addEventListener('kinetix:detach-relation', (e) =>
            detail((e as CustomEvent).detail),
        );

        const wrapper = mount(KinetixTable, {
            props: { table },
            global: { plugins: [i18n], stubs: tableStubs },
            attachTo: document.body,
        });

        await openDropdownAndSelect(wrapper);
        await confirmDialog();

        await vi.waitFor(() => {
            expect(detail).toHaveBeenCalledTimes(1);
        });
        expect(detail).toHaveBeenCalledWith(
            expect.objectContaining({
                relationship: 'tags',
                record: expect.objectContaining({ id: 7 }),
            }),
        );

        wrapper.unmount();
    });

    it('the manager turns the event into a team-prefixed detach POST with the record id', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({ status: 'success' }), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }),
        );
        vi.stubGlobal('fetch', fetchMock);
        document.cookie = 'XSRF-TOKEN=test-token';

        const wrapper = mount(KinetixRelationManager, {
            props: {
                manager: {
                    title: 'Tags',
                    relationship: 'tags',
                    table,
                    descriptor: 'signed-descriptor',
                } as any,
            },
            global: {
                plugins: [i18n],
                stubs: { KinetixTable: true },
            },
        });

        window.dispatchEvent(
            new CustomEvent('kinetix:detach-relation', {
                detail: { relationship: 'tags', record: { id: 7 } },
            }),
        );

        await vi.waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(1);
        });

        const [url, init] = fetchMock.mock.calls[0];
        // Team-prefixed: the shared route_prefix already carries {current_team}.
        expect(String(url)).toBe('/acme/_kinetix/tables/relations/detach');
        expect(JSON.parse((init as RequestInit).body as string)).toEqual({
            descriptor: 'signed-descriptor',
            ids: [7],
        });

        wrapper.unmount();
    });

    it('the attach picker renders the pivot form and posts its values with the ids', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(
                new Response(
                    JSON.stringify({ options: [{ id: 3, label: 'php' }] }),
                    {
                        status: 200,
                        headers: { 'Content-Type': 'application/json' },
                    },
                ),
            )
            .mockResolvedValueOnce(
                new Response(
                    JSON.stringify({ status: 'success', attached: 1 }),
                    {
                        status: 200,
                        headers: { 'Content-Type': 'application/json' },
                    },
                ),
            );
        vi.stubGlobal('fetch', fetchMock);
        document.cookie = 'XSRF-TOKEN=test-token';

        const wrapper = mount(KinetixRelationManager, {
            props: {
                manager: {
                    title: 'Tags',
                    relationship: 'tags',
                    table,
                    descriptor: 'signed-descriptor',
                    attachForm: {
                        schema: [
                            { type: 'text-input', name: 'role', label: 'Role' },
                        ],
                        data: { role: '' },
                        rules: {},
                        operation: 'create',
                    },
                } as any,
            },
            global: { plugins: [i18n], stubs: { KinetixTable: true } },
            attachTo: document.body,
        });

        window.dispatchEvent(
            new CustomEvent('kinetix:open-attach', {
                detail: { relationship: 'tags' },
            }),
        );

        // The modal loads the attachable options first.
        await vi.waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(1);
        });
        await vi.waitFor(() => {
            expect(
                document.body.querySelector('[role="checkbox"]'),
            ).not.toBeNull();
        });

        // Select the option and fill the pivot field.
        (
            document.body.querySelector('[role="checkbox"]') as HTMLElement
        ).click();
        await nextTick();

        const form = document.body.querySelector(
            'form#kinetix-attach-pivot-tags',
        ) as HTMLFormElement;
        expect(form).not.toBeNull();

        const roleInput = form.querySelector('input') as HTMLInputElement;
        expect(roleInput).not.toBeNull();
        roleInput.value = 'writer';
        roleInput.dispatchEvent(new Event('input', { bubbles: true }));
        await nextTick();

        // The footer button submits the form via its `form` attribute; firing
        // the form's own submit is the same code path.
        form.dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true }),
        );

        await vi.waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(2);
        });

        const [url, init] = fetchMock.mock.calls[1];
        expect(String(url)).toBe('/acme/_kinetix/tables/relations/attach');
        expect(JSON.parse((init as RequestInit).body as string)).toEqual({
            descriptor: 'signed-descriptor',
            ids: [3],
            pivot: { role: 'writer' },
        });

        wrapper.unmount();
    });
});
