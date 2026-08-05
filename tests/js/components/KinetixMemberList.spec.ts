import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import type { KinetixMemberProvision } from '@/types/kinetix';
import { i18n } from './i18n';

const load = vi.fn().mockResolvedValue(undefined);
const revoke = vi.fn().mockResolvedValue(undefined);
const resend = vi.fn().mockResolvedValue(undefined);
const updateRole = vi.fn().mockResolvedValue(undefined);
const provision = vi.fn().mockResolvedValue(undefined);
const provisions = ref<KinetixMemberProvision[]>([]);
const assignableRoles = ref<string[]>(['editor', 'viewer']);
const loading = ref(false);

vi.mock('@/composables/useKinetixMembers', async (importOriginal) => {
    const original =
        await importOriginal<
            typeof import('@/composables/useKinetixMembers')
        >();

    return {
        roleLabel: original.roleLabel,
        useKinetixMembers: () => ({
            provisions,
            assignableRoles,
            loading,
            load,
            provision,
            resend,
            updateRole,
            revoke,
        }),
    };
});

vi.mock('vue-sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

import KinetixMemberList from '@/components/KinetixMemberList.vue';

const MEMBERS: KinetixMemberProvision[] = [
    {
        id: 1,
        email: 'active@example.com',
        name: 'Active Person',
        role: 'editor',
        status: 'active',
    },
    {
        id: 2,
        email: 'pending@example.com',
        name: null,
        role: 'viewer',
        status: 'pending',
    },
    {
        id: 3,
        email: 'revoked@example.com',
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

    it('shows a skeleton on first load instead of a blank panel', () => {
        provisions.value = [];
        loading.value = true;

        const wrapper = mountList();

        expect(wrapper.find('.animate-pulse').exists()).toBe(true);
        wrapper.unmount();
    });
});
