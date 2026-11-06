import { enableAutoUnmount, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import KinetixMemberEditModal from '@/components/Members/KinetixMemberEditModal.vue';
import type {
    KinetixMemberFieldsForm,
    KinetixMemberProvision,
} from '@/types/kinetix';
import { i18n } from './i18n';

i18n.global.mergeLocaleMessage('en', {
    kinetix: {
        member_edit: 'Edit member',
        member_edit_description: "Change {name}'s role and details.",
        member_details: 'Details',
    },
});

afterEach(() => {
    document.body.innerHTML = '';
});
enableAutoUnmount(afterEach);

const MEMBER = {
    id: 1,
    identifier: 'ana@example.com',
    name: 'Ana Ruiz',
    role: 'editor',
    status: 'active',
} as KinetixMemberProvision;

const FORM: KinetixMemberFieldsForm = {
    schema: [
        { type: 'text-input', name: 'badge', label: 'Badge', columnSpan: 1 },
    ],
    data: { badge: 'A-7' },
    rules: {},
    operation: 'edit',
};

async function mountModal(props: Record<string, unknown> = {}) {
    const wrapper = mount(KinetixMemberEditModal, {
        attachTo: document.body,
        props: {
            open: true,
            member: MEMBER,
            roleOptions: { editor: 'Editor', viewer: 'Viewer' },
            form: FORM,
            ...props,
        },
        global: { plugins: [i18n] },
    });

    await vi.waitFor(() => expect(dialog()).not.toBeNull());

    return wrapper;
}

const dialog = (): HTMLElement =>
    document.querySelector('[role="dialog"]') as HTMLElement;

const saveButton = (): HTMLButtonElement =>
    dialog().querySelector('button[type="submit"]') as HTMLButtonElement;

const until = (check: () => unknown) =>
    vi.waitFor(() => expect(check()).toBeTruthy());

describe('KinetixMemberEditModal', () => {
    it('names the member in the dialog and shows a busy skeleton while loading', async () => {
        await mountModal({ form: null });

        expect(dialog().textContent).toContain("Change Ana Ruiz's role");
        expect(
            dialog()
                .querySelector('[data-test="member-edit-skeleton"]')!
                .getAttribute('aria-busy'),
        ).toBe('true');
        // Nothing to save until the member's values are here.
        expect(saveButton().disabled).toBe(true);
    });

    it('labels the role control and locks a role that is no longer assignable', async () => {
        const wrapper = await mountModal({
            roleOptions: { editor: 'Editor', admin: 'Admin (not assignable)' },
            lockedRoles: ['admin'],
        });

        const select = wrapper.findComponent({ name: 'KinetixSelect' });
        expect(select.props('disabledKeys')).toEqual(['admin']);
        expect(
            dialog().querySelector('label[for="kinetix-member-edit-role"]'),
        ).not.toBeNull();
    });

    it('emits the role and the edited fields on submit', async () => {
        const wrapper = await mountModal();

        await until(() => dialog().querySelector('input[id$="-badge"]'));
        const input = dialog().querySelector(
            'input[id$="-badge"]',
        ) as HTMLInputElement;
        expect(input.value).toBe('A-7');

        input.value = 'B-9';
        input.dispatchEvent(new Event('input'));
        (dialog().querySelector('form') as HTMLFormElement).requestSubmit();

        await until(() => wrapper.emitted('submit'));
        expect(wrapper.emitted('submit')![0]).toEqual([
            'editor',
            { badge: 'B-9' },
        ]);
    });

    it('shows Save as busy while submitting and cannot be submitted twice', async () => {
        const wrapper = await mountModal({ submitting: true });

        expect(saveButton().getAttribute('aria-busy')).toBe('true');
        expect(saveButton().disabled).toBe(true);

        await until(() => dialog().querySelector('input[id$="-badge"]'));
        (dialog().querySelector('form') as HTMLFormElement).requestSubmit();
        expect(wrapper.emitted('submit')).toBeUndefined();
    });

    it('marks an errored field invalid and moves focus to the first one', async () => {
        const wrapper = await mountModal();

        await until(() => dialog().querySelector('input[id$="-badge"]'));
        await wrapper.setProps({ errors: { badge: 'Badge is taken.' } });

        const input = dialog().querySelector(
            'input[id$="-badge"]',
        ) as HTMLInputElement;
        await until(() => input.getAttribute('aria-invalid') === 'true');
        expect(dialog().textContent).toContain('Badge is taken.');
        await until(() => document.activeElement === input);
    });

    it('asks to close from Cancel', async () => {
        const wrapper = await mountModal();

        const cancel = [...dialog().querySelectorAll('button')].find(
            (b) => b.textContent?.trim() === 'Cancel',
        ) as HTMLButtonElement;
        cancel.click();

        await until(() => wrapper.emitted('update:open'));
        expect(wrapper.emitted('update:open')![0]).toEqual([false]);
    });
});
