import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { createI18n } from 'vue-i18n';

const load = vi.fn();
const save = vi.fn().mockResolvedValue({});
const remove = vi.fn().mockResolvedValue({});
const features = ref<any[]>([]);
const roles = ref<any[]>([]);

vi.mock('@/composables/useKinetixRoles', () => ({
    useKinetixRoles: () => ({
        features,
        roles,
        loading: ref(false),
        load,
        save,
        remove,
    }),
}));

import KinetixRoleMatrix from '@/components/KinetixRoleMatrix.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: {
        en: {
            kinetix: {
                roles_title: 'Roles & Permissions',
                create_role: 'New role',
                role_name: 'Role name',
                role_members: '{count} members',
                role_permissions_count: '{count} permissions',
                role_matrix_hint: 'Toggle permissions per module.',
                role_matrix_module: 'Module',
                access: 'Access',
                view_any: 'View all',
                view: 'View',
                create: 'Create',
                delete_any: 'Delete multiple',
                restore: 'Restore',
                force_delete: 'Delete permanently',
                edit: 'Edit',
                delete: 'Delete',
                cancel: 'Cancel',
                save: 'Save',
                saved: 'Saved',
                confirm_delete: 'Are you sure?',
                no_roles: 'No roles yet.',
            },
        },
    },
});

const CATALOG = [
    {
        name: 'users',
        label: 'Users',
        abilities: [
            { key: 'view', label: 'View', permission: 'users.view' },
            { key: 'create', label: 'Create', permission: 'users.create' },
        ],
    },
    {
        name: 'orders',
        label: 'Orders',
        abilities: [
            { key: 'view', label: 'View', permission: 'orders.view' },
            { key: 'refund', label: 'Refund', permission: 'orders.refund' },
        ],
    },
];

const mountMatrix = () =>
    // The reka dialog teleports to <body>, so attach and query the document.
    mount(KinetixRoleMatrix, {
        attachTo: document.body,
        global: { plugins: [i18n] },
    });

const bodyText = () => document.body.textContent ?? '';

describe('KinetixRoleMatrix', () => {
    beforeEach(() => {
        // Teleported dialogs from a failed test would otherwise leak into the
        // next test's document-level queries.
        document.body.innerHTML = '';
        features.value = CATALOG;
        roles.value = [
            {
                id: 1,
                name: 'editor',
                permissions: ['users.view', 'orders.view'],
                usersCount: 8,
            },
        ];
        save.mockClear();
    });

    it('renders role cards with member and permission counts', () => {
        const w = mountMatrix();

        expect(w.text()).toContain('editor');
        expect(w.text()).toContain('8 members');
        expect(w.text()).toContain('2 permissions');
    });

    it('columns are canonical-only; custom abilities live in an expandable row', async () => {
        const w = mountMatrix();
        await w.get('[title="Edit"]').trigger('click');
        await w.vm.$nextTick();

        const headers = [...document.body.querySelectorAll('th')].map((th) =>
            th.textContent?.trim(),
        );
        // Canonical keys only — the custom `refund` never becomes a column.
        expect(headers).toEqual(['Module', 'View', 'Create']);

        // Undeclared abilities render as an em-dash placeholder.
        expect(bodyText()).toContain('—');

        // Orders carries one custom ability → a 0/1 chip; expanding reveals
        // the full-label checkbox and toggling it updates the count.
        const chip = [
            ...document.body.querySelectorAll('[aria-expanded]'),
        ].find((el) => el.textContent?.includes('0/1')) as HTMLElement;
        expect(chip).toBeTruthy();
        chip.click();
        await w.vm.$nextTick();

        expect(bodyText()).toContain('Refund');
        expect(bodyText()).toContain('orders.refund');

        const custom = [...document.body.querySelectorAll('tr td label')].find(
            (label) => label.textContent?.includes('Refund'),
        ) as HTMLElement;
        // reka's CheckboxRoot renders a role=checkbox button.
        custom.querySelector<HTMLElement>('[role="checkbox"]')?.click();
        await w.vm.$nextTick();

        expect(chip.textContent).toContain('1/1');
        w.unmount();
    });

    it('renders access-only modules under a leading Access column', async () => {
        features.value = [
            {
                name: 'reports',
                label: 'Reports',
                abilities: [
                    {
                        key: 'access',
                        label: 'Access',
                        permission: 'reports.access',
                    },
                ],
            },
            ...CATALOG,
        ];

        const w = mountMatrix();
        await w.get('[title="Edit"]').trigger('click');
        await w.vm.$nextTick();

        const headers = [...document.body.querySelectorAll('th')].map((th) =>
            th.textContent?.trim(),
        );
        expect(headers).toEqual(['Module', 'Access', 'View', 'Create']);
        w.unmount();
    });

    it('groups features into titled sections when a group is declared', async () => {
        features.value = [
            { ...CATALOG[0], group: 'HR' },
            { ...CATALOG[1], group: null },
        ];

        const w = mountMatrix();
        await w.get('[title="Edit"]').trigger('click');
        await w.vm.$nextTick();

        expect(bodyText()).toContain('HR');
        // The grouped section renders before the ungrouped remainder.
        const rows = [...document.body.querySelectorAll('tbody tr')].map(
            (tr) => tr.textContent ?? '',
        );
        expect(rows[0]).toContain('HR');
        expect(rows[1]).toContain('Users');
        w.unmount();
    });

    it('canonical column headers stay generic even when a feature customizes its labels', async () => {
        // Regression: `members` is evaluated first and carries custom labels
        // for canonical keys — those must NOT become the shared column
        // headers above every other feature's row.
        features.value = [
            {
                name: 'members',
                label: 'Members',
                abilities: [
                    {
                        key: 'viewAny',
                        label: 'View members',
                        permission: 'members.viewAny',
                    },
                    {
                        key: 'update',
                        label: 'Change member role',
                        permission: 'members.update',
                    },
                ],
            },
            ...CATALOG,
        ];

        const w = mountMatrix();
        await w.get('[title="Edit"]').trigger('click');
        await w.vm.$nextTick();

        const headers = [...document.body.querySelectorAll('th')].map((th) =>
            th.textContent?.trim(),
        );
        // Generic translations for canonical keys; customs are never columns.
        expect(headers).toEqual([
            'Module',
            'View all',
            'View',
            'Create',
            'Edit',
        ]);
        expect(headers).not.toContain('Change member role');
        expect(headers).not.toContain('View members');
        w.unmount();
    });

    it('clicking a module name toggles its whole row and saves the union', async () => {
        const w = mountMatrix();
        await w.get('[title="Edit"]').trigger('click');
        await w.vm.$nextTick();

        // Toggle the full "Orders" row on (refund was off → adds every ability).
        // The module name is a real <button> now (keyboard/AT accessible).
        const orders = [
            ...document.body.querySelectorAll('tbody td button'),
        ].find((btn) => btn.textContent?.trim() === 'Orders') as HTMLElement;
        orders.click();
        await w.vm.$nextTick();

        document.body
            .querySelector<HTMLFormElement>('form')!
            .dispatchEvent(new Event('submit', { cancelable: true }));
        await w.vm.$nextTick();

        expect(save).toHaveBeenCalledWith({
            id: 1,
            name: 'editor',
            permissions: expect.arrayContaining([
                'users.view',
                'orders.view',
                'orders.refund',
            ]),
        });
        w.unmount();
    });
});
