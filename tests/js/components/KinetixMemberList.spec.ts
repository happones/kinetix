import { enableAutoUnmount, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import type {
    KinetixMemberAbilities,
    KinetixMemberFieldsForm,
    KinetixMemberProvision,
    KinetixMemberRole,
} from '@/types/kinetix';
import { i18n } from './i18n';

i18n.global.mergeLocaleMessage('en', {
    kinetix: {
        edit: 'Edit',
        member_edit: 'Edit member',
        member_edit_named: 'Edit {name}',
        member_edit_description: "Change {name}'s role and details.",
        member_details: 'Details',
        member_updated: 'Member updated.',
        member_fields_load_failed: "Couldn't load this member's details.",
    },
});

const load = vi.fn().mockResolvedValue(undefined);
const revoke = vi.fn().mockResolvedValue(undefined);
const resend = vi.fn().mockResolvedValue(undefined);
const updateRole = vi.fn().mockResolvedValue(undefined);
const provision = vi.fn().mockResolvedValue(undefined);
const issueCredential = vi.fn().mockResolvedValue(undefined);
const provisions = ref<KinetixMemberProvision[]>([]);
const assignableRoles = ref<string[]>(['editor', 'viewer']);
const roles = ref<KinetixMemberRole[]>([]);
const reservedRoles = ref<string[]>(['super-admin']);
const identifierField = ref<'email' | 'username' | 'phone'>('email');
const ALL: KinetixMemberAbilities = {
    provision: true,
    update: true,
    revoke: true,
    credentials: true,
};
const can = ref<KinetixMemberAbilities>({ ...ALL });
const loading = ref(false);
const hasFields = ref(false);
const fieldsForm = ref<KinetixMemberFieldsForm | null>(null);
const memberFields = vi.fn();
const updateMember = vi.fn().mockResolvedValue(undefined);

vi.mock('@/composables/useKinetixMembers', async (importOriginal) => {
    const original =
        await importOriginal<
            typeof import('@/composables/useKinetixMembers')
        >();

    return {
        roleLabel: original.roleLabel,
        memberFieldErrors: original.memberFieldErrors,
        memberRoleLabel: original.memberRoleLabel,
        useKinetixMembers: () => ({
            provisions,
            assignableRoles,
            roles,
            reservedRoles,
            identifierField,
            can,
            hasFields,
            fieldsForm,
            loading,
            load,
            provision,
            issueCredential,
            resend,
            updateRole,
            memberFields,
            updateMember,
            revoke,
        }),
    };
});

vi.mock('vue-sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

import KinetixMemberList from '@/components/KinetixMemberList.vue';
import KinetixSelect from '@/components/KinetixSelect.vue';

// Registered before enableAutoUnmount so it runs AFTER the unmount: clearing
// the body under a still-mounted app breaks its next patch.
afterEach(() => {
    document.body.innerHTML = '';
});
enableAutoUnmount(afterEach);

const MEMBERS: KinetixMemberProvision[] = [
    {
        id: 1,
        email: 'active@example.com',
        identifier: 'active@example.com',
        name: 'Active Person',
        role: 'editor',
        status: 'active',
    },
    {
        id: 2,
        email: 'pending@example.com',
        identifier: 'pending@example.com',
        name: null,
        role: 'viewer',
        status: 'pending',
    },
    {
        id: 3,
        email: 'revoked@example.com',
        identifier: 'revoked@example.com',
        name: 'Gone Person',
        role: 'editor',
        status: 'revoked',
    },
] as KinetixMemberProvision[];

function mountList() {
    return mount(KinetixMemberList, {
        attachTo: document.body,
        global: { plugins: [i18n] },
    });
}

describe('KinetixMemberList', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        provisions.value = [...MEMBERS];
        loading.value = false;
        assignableRoles.value = ['editor', 'viewer'];
        roles.value = [];
        can.value = { ...ALL };
        hasFields.value = false;
        fieldsForm.value = null;
        memberFields.mockReset();
        updateMember.mockReset().mockResolvedValue(undefined);
    });

    const buttonLabelled = (
        wrapper: ReturnType<typeof mountList>,
        prefix: string,
    ) =>
        wrapper
            .findAll('button')
            .find((b) => (b.attributes('aria-label') ?? '').startsWith(prefix));

    it('hides every action the viewer may not take', () => {
        can.value = {
            provision: false,
            update: false,
            revoke: false,
            credentials: false,
        };

        const wrapper = mountList();

        // No provisioning form, no role select, no row buttons.
        expect(wrapper.find('form').exists()).toBe(false);
        expect(buttonLabelled(wrapper, 'Remove')).toBeUndefined();
        expect(buttonLabelled(wrapper, 'Resend')).toBeUndefined();
        // The role still reads, as text.
        expect(wrapper.findAll('[data-test="member-role-text"]').length).toBe(
            3,
        );
        wrapper.unmount();
    });

    it('keeps a role that is no longer assignable visible on its member', () => {
        provisions.value = [
            {
                ...MEMBERS[0],
                role: 'admin',
            },
        ];

        const wrapper = mountList();

        // The select lists what the member holds today instead of a blank,
        // locked so nobody re-picks it.
        // The first select is the provisioning form's; the row's is last.
        const select = wrapper.findAllComponents(KinetixSelect).at(-1)!;
        expect(select.props('options')).toEqual({
            editor: 'Editor',
            viewer: 'Viewer',
            admin: 'Admin (not assignable)',
        });
        expect(select.props('disabledKeys')).toEqual(['admin']);
        wrapper.unmount();
    });

    it('labels roles the way the app named them', () => {
        roles.value = [
            {
                name: 'editor',
                label: 'Content editor',
                permissions: null,
                global: false,
            },
        ];
        provisions.value = [{ ...MEMBERS[2] }];

        const wrapper = mountList();

        expect(wrapper.text()).toContain('Content editor');
        wrapper.unmount();
    });

    it('shows a credential the server hands back, once, ready to copy', async () => {
        provision.mockResolvedValueOnce({
            ...MEMBERS[1],
            credential: {
                type: 'password',
                value: 'Temp-1234',
                expiresAt: null,
            },
        });

        const wrapper = mountList();

        await wrapper.get('#kinetix-member-email').setValue('new@example.com');
        await wrapper.get('form').trigger('submit');
        await new Promise((resolve) => setTimeout(resolve, 0));
        await wrapper.vm.$nextTick();

        expect(provision).toHaveBeenCalledWith(
            'new@example.com',
            'editor',
            null,
        );

        const dialog = document.querySelector(
            '[data-test="member-credential"]',
        ) as HTMLElement;
        expect(dialog).not.toBeNull();
        expect((dialog.querySelector('input') as HTMLInputElement).value).toBe(
            'Temp-1234',
        );
        expect(dialog.textContent).toContain('Temporary password');
        wrapper.unmount();
    });

    it('issues a new activation link for a pending member', async () => {
        issueCredential.mockResolvedValueOnce({
            ...MEMBERS[1],
            credential: {
                type: 'link',
                value: 'https://app.test/activate/2',
                expiresAt: null,
            },
        });

        const wrapper = mountList();

        await buttonLabelled(
            wrapper,
            'New link — pending@example.com',
        )!.trigger('click');
        await new Promise((resolve) => setTimeout(resolve, 0));
        await wrapper.vm.$nextTick();

        expect(issueCredential).toHaveBeenCalledWith(MEMBERS[1]);
        expect(
            (
                document.querySelector(
                    '[data-test="member-credential"] input',
                ) as HTMLInputElement
            ).value,
        ).toBe('https://app.test/activate/2');
        // The active member gets a password instead.
        expect(
            buttonLabelled(wrapper, 'New password — active@example.com'),
        ).toBeDefined();
        wrapper.unmount();
    });

    it('filters by status once the list is long enough to need it', async () => {
        provisions.value = Array.from({ length: 6 }, (_, index) => ({
            ...MEMBERS[index % 3],
            id: index + 1,
            identifier: `person${index}@example.com`,
            name: null,
        }));

        const wrapper = mountList();

        const filters = wrapper.findAll('[aria-pressed]');
        expect(filters.map((f) => f.text())).toEqual([
            'All 6',
            'Active 2',
            'Pending 2',
            'Revoked 2',
        ]);

        await filters[2].trigger('click');

        expect(wrapper.findAll('[data-test="member-row"]')).toHaveLength(2);
        wrapper.unmount();
    });

    it('says when an invitation link has expired', () => {
        provisions.value = [{ ...MEMBERS[1], expired: true }];

        const wrapper = mountList();

        expect(wrapper.get('[data-test="member-status-detail"]').text()).toBe(
            'Activation link expired — resend it.',
        );
        wrapper.unmount();
    });

    it('renders one row per provision with truncating identity', () => {
        const wrapper = mountList();

        expect(wrapper.text()).toContain('Active Person');
        expect(wrapper.text()).toContain('pending@example.com');
        expect(wrapper.findAll('.truncate').length).toBeGreaterThan(0);
        wrapper.unmount();
    });

    it('revoked rows show the role as text, not an editable select', () => {
        const wrapper = mountList();

        const selects = wrapper
            .findAll('[aria-label]')
            .filter((node) =>
                (node.attributes('aria-label') ?? '').includes(
                    'revoked@example.com',
                ),
            );

        // No role select (nor resend/revoke controls) for the revoked member.
        expect(selects).toHaveLength(0);
        wrapper.unmount();
    });

    it('revoke asks for confirmation before firing the request', async () => {
        const wrapper = mountList();

        // The row's role select is ALSO a button carrying the email in its
        // aria-label, so match the revoke label specifically.
        const revokeButton = wrapper
            .findAll('button')
            .find((b) =>
                (b.attributes('aria-label') ?? '').startsWith(
                    'Remove — active@example.com',
                ),
            );

        expect(revokeButton).toBeDefined();
        await revokeButton!.trigger('click');
        await wrapper.vm.$nextTick();

        // Not yet — the confirm modal owns the destructive step.
        expect(revoke).not.toHaveBeenCalled();

        // The shared KinetixConfirmModal teleports a role="dialog" to <body>.
        expect(document.querySelector('[role="dialog"]')).not.toBeNull();
        wrapper.unmount();
    });

    it('asks to cancel a pending invitation, not to strip a role, with the action on the button', async () => {
        const wrapper = mountList();

        await wrapper
            .findAll('button')
            .find((b) =>
                (b.attributes('aria-label') ?? '').startsWith(
                    'Remove — pending@example.com',
                ),
            )!
            .trigger('click');
        await wrapper.vm.$nextTick();

        const dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(dialog.textContent).toContain(
            'Cancel the invitation for pending@example.com?',
        );
        expect(
            [...dialog.querySelectorAll('button')].map((b) =>
                b.textContent?.trim(),
            ),
        ).toContain('Remove');
        wrapper.unmount();
    });

    it('shows a skeleton on first load instead of a blank panel', () => {
        provisions.value = [];
        loading.value = true;

        const wrapper = mountList();

        expect(wrapper.find('.animate-pulse').exists()).toBe(true);
        wrapper.unmount();
    });

    it('takes the heading and empty text an app gives it', () => {
        const w = mount(KinetixMemberList, {
            props: {
                title: 'Invitations',
                emptyText: 'No pending invitations.',
            },
            global: { plugins: [i18n] },
        });

        expect(w.find('h2').text()).toBe('Invitations');
        w.unmount();
    });
});

const fieldsFormOf = (
    data: Record<string, unknown> = {},
): KinetixMemberFieldsForm => ({
    schema: [
        {
            type: 'text-input',
            name: 'badge',
            label: 'Badge number',
            columnSpan: 1,
        },
    ],
    data: { badge: '', ...data },
    rules: {},
    operation: 'edit',
});

const until = async (check: () => unknown): Promise<void> => {
    await vi.waitFor(() => expect(check()).toBeTruthy());
};

describe('KinetixMemberList with member fields', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        provisions.value = [...MEMBERS];
        loading.value = false;
        assignableRoles.value = ['editor', 'viewer'];
        roles.value = [];
        can.value = { ...ALL };
        hasFields.value = true;
        fieldsForm.value = fieldsFormOf();
        memberFields
            .mockReset()
            .mockResolvedValue(fieldsFormOf({ badge: 'A-7' }));
        updateMember.mockReset().mockResolvedValue(undefined);
    });

    const editButton = (wrapper: ReturnType<typeof mountList>, who: string) =>
        wrapper
            .findAll('[data-test="member-edit"]')
            .find((b) => b.attributes('aria-label') === `Edit ${who}`);

    const buttonLabelled = (
        wrapper: ReturnType<typeof mountList>,
        prefix: string,
    ) =>
        wrapper
            .findAll('button')
            .find((b) => (b.attributes('aria-label') ?? '').startsWith(prefix));

    const dialog = (): HTMLElement | null =>
        document.querySelector('[role="dialog"]');

    it('offers Edit on non-revoked rows with update rights, in place of the inline select', () => {
        const wrapper = mountList();

        expect(wrapper.findAll('[data-test="member-edit"]')).toHaveLength(2);
        expect(editButton(wrapper, 'Gone Person')).toBeUndefined();
        // Only the provisioner's select remains: rows show the role as text.
        expect(wrapper.findAllComponents(KinetixSelect)).toHaveLength(1);
        expect(wrapper.findAll('[data-test="member-role-text"]')).toHaveLength(
            3,
        );

        can.value = { ...ALL, update: false };
        const readOnly = mountList();
        expect(readOnly.findAll('[data-test="member-edit"]')).toHaveLength(0);
    });

    it('keeps the inline select when the app has no member fields', () => {
        hasFields.value = false;
        fieldsForm.value = null;

        const wrapper = mountList();

        expect(wrapper.findAll('[data-test="member-edit"]')).toHaveLength(0);
        expect(wrapper.findAllComponents(KinetixSelect)).toHaveLength(3);
    });

    it('loads the member, saves role and fields, then closes and toasts', async () => {
        const wrapper = mountList();

        await editButton(wrapper, 'Active Person')!.trigger('click');
        await until(() =>
            document.querySelector('[role="dialog"] input[id$="-badge"]'),
        );

        expect(memberFields).toHaveBeenCalledWith(MEMBERS[0]);
        const input = document.querySelector(
            '[role="dialog"] input[id$="-badge"]',
        ) as HTMLInputElement;
        expect(input.value).toBe('A-7');

        input.value = 'B-9';
        input.dispatchEvent(new Event('input'));
        (
            document.querySelector(
                'form#kinetix-member-edit-form',
            ) as HTMLFormElement
        ).requestSubmit();

        await until(() => updateMember.mock.calls.length > 0);
        expect(updateMember).toHaveBeenCalledWith(MEMBERS[0], {
            role: 'editor',
            fields: { badge: 'B-9' },
        });
        await until(() => dialog() === null);
        expect(load.mock.calls.length).toBeGreaterThan(1);
    });

    it('keeps the dialog open and shows the field errors on a 422', async () => {
        const error = Object.assign(new Error('Invalid'), {
            status: 422,
            errors: { 'fields.badge': ['Badge is taken.'], role: ['Nope'] },
        });
        updateMember.mockRejectedValueOnce(error);

        const wrapper = mountList();

        await editButton(wrapper, 'Active Person')!.trigger('click');
        await until(() =>
            document.querySelector('[role="dialog"] input[id$="-badge"]'),
        );
        (
            document.querySelector(
                'form#kinetix-member-edit-form',
            ) as HTMLFormElement
        ).requestSubmit();

        await until(() =>
            document.body.textContent?.includes('Badge is taken.'),
        );
        expect(dialog()).not.toBeNull();
        expect(
            document
                .querySelector('[role="dialog"] input[id$="-badge"]')!
                .getAttribute('aria-invalid'),
        ).toBe('true');
    });

    it('closes the dialog with a toast when the member cannot be loaded', async () => {
        memberFields.mockRejectedValueOnce(new Error('boom'));
        const { toast } = await import('vue-sonner');

        const wrapper = mountList();

        await editButton(wrapper, 'Active Person')!.trigger('click');

        await until(
            () => (toast.error as ReturnType<typeof vi.fn>).mock.calls.length,
        );
        expect(toast.error).toHaveBeenCalledWith(
            "Couldn't load this member's details.",
        );
        await until(() => dialog() === null);
    });

    it('provisions with the fields and clears the form only on success', async () => {
        provision.mockRejectedValueOnce(
            Object.assign(new Error('Invalid'), {
                status: 422,
                errors: { 'fields.badge': ['Required.'] },
            }),
        );

        const wrapper = mountList();

        await wrapper.get('#kinetix-member-email').setValue('new@example.com');
        await until(() => wrapper.find('input[id$="-badge"]').exists());
        await wrapper.get('input[id$="-badge"]').setValue('Z-1');
        await wrapper.get('form').trigger('submit');

        await until(() => provision.mock.calls.length > 0);
        expect(provision).toHaveBeenCalledWith(
            'new@example.com',
            'editor',
            null,
            { badge: 'Z-1' },
        );

        // Rejected: what was typed is still there, the error under the field.
        await until(() => wrapper.text().includes('Required.'));
        expect(
            (wrapper.get('#kinetix-member-email').element as HTMLInputElement)
                .value,
        ).toBe('new@example.com');
        expect(
            (wrapper.get('input[id$="-badge"]').element as HTMLInputElement)
                .value,
        ).toBe('Z-1');

        // Accepted: now it clears.
        await wrapper.get('form').trigger('submit');
        await until(
            () =>
                (
                    wrapper.get('#kinetix-member-email')
                        .element as HTMLInputElement
                ).value === '',
        );
        expect(
            (wrapper.get('input[id$="-badge"]').element as HTMLInputElement)
                .value,
        ).toBe('');
    });

    it('keeps the typed custom fields across a reload after a row action', async () => {
        load.mockImplementation(async () => {
            fieldsForm.value = fieldsFormOf();
        });

        const wrapper = mountList();

        await until(() => wrapper.find('input[id$="-badge"]').exists());
        await wrapper.get('input[id$="-badge"]').setValue('Z-1');

        await buttonLabelled(wrapper, 'Resend — pending@example.com')!.trigger(
            'click',
        );
        await until(() => resend.mock.calls.length > 0);
        await until(() => load.mock.calls.length > 1);
        await wrapper.vm.$nextTick();

        expect(
            (wrapper.get('input[id$="-badge"]').element as HTMLInputElement)
                .value,
        ).toBe('Z-1');
        load.mockReset().mockResolvedValue(undefined);
    });

    it('does not reset the provisioner when a pending row action blocks the submit', async () => {
        let release!: () => void;
        resend.mockReturnValueOnce(
            new Promise<void>((resolve) => (release = resolve)),
        );

        const wrapper = mountList();

        await until(() => wrapper.find('input[id$="-badge"]').exists());
        await buttonLabelled(wrapper, 'Resend — pending@example.com')!.trigger(
            'click',
        );
        await wrapper.get('#kinetix-member-email').setValue('new@example.com');
        await wrapper.get('input[id$="-badge"]').setValue('Z-2');
        await wrapper.get('form').trigger('submit');
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(provision).not.toHaveBeenCalled();
        expect(
            (wrapper.get('#kinetix-member-email').element as HTMLInputElement)
                .value,
        ).toBe('new@example.com');
        expect(
            (wrapper.get('input[id$="-badge"]').element as HTMLInputElement)
                .value,
        ).toBe('Z-2');

        release();
    });
});
