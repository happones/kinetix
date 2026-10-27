import { enableAutoUnmount, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import KinetixRoleEditorModal from '@/components/Roles/KinetixRoleEditorModal.vue';
import type { KinetixPermissionFeature, KinetixRole } from '@/types/kinetix';
import { i18n } from './i18n';

// A failed assertion skips the test's own unmount; never let its teleported
// dialog leak into the next test's document queries.
afterEach(() => {
    document.body.innerHTML = '';
});
enableAutoUnmount(afterEach);

async function mountModal(
    options: {
        role?: KinetixRole | null;
        canCreateGlobal?: boolean;
        features?: KinetixPermissionFeature[];
    } = {},
) {
    // Open AFTER mount so the draft-seeding `watch(open)` fires and reka's
    // portal renders before the assertions query the document.
    const wrapper = mount(KinetixRoleEditorModal, {
        attachTo: document.body,
        props: {
            open: false,
            role: options.role ?? null,
            features: options.features ?? [],
            canCreateGlobal: options.canCreateGlobal ?? false,
        },
        global: { plugins: [i18n] },
    });

    await wrapper.setProps({ open: true });
    await wrapper.vm.$nextTick();

    return wrapper;
}

const globalToggle = () =>
    Array.from(document.querySelectorAll('label')).find((label) =>
        label.textContent?.includes('Global role'),
    );

describe('KinetixRoleEditorModal — global toggle', () => {
    it('hides the toggle for non-super-admins', async () => {
        const wrapper = await mountModal({ canCreateGlobal: false });

        expect(globalToggle()).toBeUndefined();
        wrapper.unmount();
    });

    it('opens anchored near the top, like every form dialog', async () => {
        const wrapper = await mountModal();

        const dialog = document.body.querySelector(
            '[role="dialog"]',
        ) as HTMLElement;
        expect((dialog.firstElementChild as HTMLElement).className).toContain(
            'sm:items-start',
        );
        wrapper.unmount();
    });

    it('hides the toggle when editing (a role cannot change teams)', async () => {
        const wrapper = await mountModal({
            canCreateGlobal: true,
            role: { id: 5, name: 'writers', permissions: [] },
        });

        expect(globalToggle()).toBeUndefined();
        wrapper.unmount();
    });

    it('emits global:true when a super-admin checks it on create', async () => {
        const wrapper = await mountModal({ canCreateGlobal: true });

        const toggle = globalToggle();
        expect(toggle).toBeDefined();

        // Check the box, name the role, submit.
        (toggle!.querySelector('button, input') as HTMLElement).click();
        await wrapper.vm.$nextTick();

        const name = document.querySelector(
            '#kx-role-matrix-name',
        ) as HTMLInputElement;
        name.value = 'auditor';
        name.dispatchEvent(new Event('input'));
        await wrapper.vm.$nextTick();

        (document.querySelector('form') as HTMLFormElement).dispatchEvent(
            new Event('submit', { cancelable: true }),
        );
        await wrapper.vm.$nextTick();

        const saved = wrapper.emitted('save')?.[0]?.[0] as KinetixRole & {
            global?: boolean;
        };

        expect(saved).toBeDefined();
        expect(saved.name).toBe('auditor');
        expect(saved.global).toBe(true);
        wrapper.unmount();
    });

    it('omits the flag when the toggle stays unchecked', async () => {
        const wrapper = await mountModal({ canCreateGlobal: true });

        const name = document.querySelector(
            '#kx-role-matrix-name',
        ) as HTMLInputElement;
        name.value = 'writers';
        name.dispatchEvent(new Event('input'));
        await wrapper.vm.$nextTick();

        (document.querySelector('form') as HTMLFormElement).dispatchEvent(
            new Event('submit', { cancelable: true }),
        );
        await wrapper.vm.$nextTick();

        const saved = wrapper.emitted('save')?.[0]?.[0] as KinetixRole & {
            global?: boolean;
        };

        expect(saved.global).toBeUndefined();
        wrapper.unmount();
    });
});

const CATALOG: KinetixPermissionFeature[] = [
    {
        name: 'users',
        label: 'Users',
        group: 'Admin',
        abilities: [
            { key: 'view', label: 'View', permission: 'users.view' },
            { key: 'create', label: 'Create', permission: 'users.create' },
        ],
    },
    {
        name: 'orders',
        label: 'Orders',
        group: 'Sales',
        abilities: [
            { key: 'view', label: 'View', permission: 'orders.view' },
            { key: 'refund', label: 'Refund', permission: 'orders.refund' },
        ],
    },
    {
        name: 'invoices',
        label: 'Facturación',
        group: 'Sales',
        abilities: [
            { key: 'view', label: 'View', permission: 'invoices.view' },
        ],
    },
];

const byTest = (id: string) =>
    document.querySelector(`[data-test="${id}"]`) as HTMLElement;

const filterInput = () =>
    document.querySelector('input[type="search"]') as HTMLInputElement;

async function submitDraft(
    wrapper: Awaited<ReturnType<typeof mountModal>>,
): Promise<string[]> {
    (document.querySelector('form') as HTMLFormElement).dispatchEvent(
        new Event('submit', { cancelable: true }),
    );
    await wrapper.vm.$nextTick();

    const saved = wrapper.emitted('save')?.at(-1)?.[0] as KinetixRole;

    return [...saved.permissions].sort();
}

describe('KinetixRoleEditorModal — layout and bulk selection', () => {
    const editing: KinetixRole = {
        id: 1,
        name: 'editor',
        permissions: ['users.view'],
    };

    it('pins the footer outside the scrolling matrix and submits by form id', async () => {
        const wrapper = await mountModal({ features: CATALOG });

        const form = document.querySelector('form') as HTMLFormElement;
        const submit = document.querySelector(
            'button[type="submit"]',
        ) as HTMLButtonElement;

        // The submit button lives in the modal footer, not in the form.
        expect(form.contains(submit)).toBe(false);
        expect(submit.getAttribute('form')).toBe(form.id);
        expect(submit.textContent?.trim()).toBe('Create');

        // Only the matrix scrolls.
        const scroller = document.querySelector('table')!
            .parentElement as HTMLElement;
        expect(scroller.className).toContain('overflow-auto');
        expect(scroller.className).toContain('min-h-0');
        // happy-dom proxies form elements, so compare by id, not identity.
        expect(scroller.closest('form')?.id).toBe(form.id);
        wrapper.unmount();
    });

    it('select all grants every permission, then clears them all', async () => {
        const wrapper = await mountModal({ features: CATALOG, role: editing });

        const selectAll = byTest('role-editor-select-all');
        expect(selectAll.getAttribute('data-state')).toBe('indeterminate');
        expect(byTest('role-editor-count').textContent).toContain('1');

        selectAll.click();
        await wrapper.vm.$nextTick();

        expect(selectAll.getAttribute('data-state')).toBe('checked');
        expect(await submitDraft(wrapper)).toEqual([
            'invoices.view',
            'orders.refund',
            'orders.view',
            'users.create',
            'users.view',
        ]);

        byTest('role-editor-clear-all').click();
        await wrapper.vm.$nextTick();

        expect(await submitDraft(wrapper)).toEqual([]);
        expect(
            (byTest('role-editor-clear-all') as HTMLButtonElement).disabled,
        ).toBe(true);
        wrapper.unmount();
    });

    it('a column header grants that ability on every module', async () => {
        const wrapper = await mountModal({ features: CATALOG, role: editing });

        // The first ability column is "view" (shared by all three modules).
        const column = document.querySelector(
            'th [role="checkbox"]',
        ) as HTMLElement;
        expect(column.getAttribute('aria-label')).toMatch(
            /^Grant “.+” on every module$/,
        );
        expect(column.getAttribute('data-state')).toBe('indeterminate');

        column.click();
        await wrapper.vm.$nextTick();

        expect(await submitDraft(wrapper)).toEqual([
            'invoices.view',
            'orders.view',
            'users.view',
        ]);
        wrapper.unmount();
    });

    it('a group title grants every module in the group', async () => {
        const wrapper = await mountModal({ features: CATALOG, role: editing });

        const sales = [...document.querySelectorAll('tbody label')]
            .find((label) => label.textContent?.trim() === 'Sales')!
            .querySelector('[role="checkbox"]') as HTMLElement;
        sales.click();
        await wrapper.vm.$nextTick();

        expect(await submitDraft(wrapper)).toEqual([
            'invoices.view',
            'orders.refund',
            'orders.view',
            'users.view',
        ]);
        wrapper.unmount();
    });

    it('filters modules (accent-insensitive) and scopes select all to them', async () => {
        const wrapper = await mountModal({ features: CATALOG, role: editing });

        const input = filterInput();
        input.value = 'facturacion';
        input.dispatchEvent(new Event('input'));
        await wrapper.vm.$nextTick();

        const rows = document.querySelector('tbody')!.textContent ?? '';
        expect(rows).toContain('Facturación');
        expect(rows).not.toContain('Orders');

        byTest('role-editor-select-all').click();
        await wrapper.vm.$nextTick();

        // Only the shown module was added; the hidden grant is untouched.
        expect(await submitDraft(wrapper)).toEqual([
            'invoices.view',
            'users.view',
        ]);
        wrapper.unmount();
    });

    it('finds a module by one of its custom abilities, and says when nothing matches', async () => {
        const wrapper = await mountModal({ features: CATALOG });

        const input = filterInput();
        input.value = 'refund';
        input.dispatchEvent(new Event('input'));
        await wrapper.vm.$nextTick();

        expect(document.querySelector('tbody')!.textContent).toContain(
            'Orders',
        );

        input.value = 'zzz';
        input.dispatchEvent(new Event('input'));
        await wrapper.vm.$nextTick();

        expect(document.querySelector('tbody')!.textContent).toContain(
            'No module matches “zzz”.',
        );
        wrapper.unmount();
    });

    it('Enter in the filter does not submit the role', async () => {
        const wrapper = await mountModal({ features: CATALOG, role: editing });

        const enter = new KeyboardEvent('keydown', {
            key: 'Enter',
            cancelable: true,
            bubbles: true,
        });
        filterInput().dispatchEvent(enter);

        expect(enter.defaultPrevented).toBe(true);
        wrapper.unmount();
    });
});
