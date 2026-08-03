import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type {
    KinetixMemberProvision,
    KinetixSharedProps,
} from '@/types/kinetix';

interface MembersResponse {
    provisions: KinetixMemberProvision[];
    assignable_roles: string[];
}

/**
 * Headline-case a role slug for display (`support-agent` → `Support Agent`).
 * Shared by every role select in the membership UI, so the provisioner and the
 * member list render the same labels on the same screen.
 */
export function roleLabel(name: string): string {
    return name.replace(/[-_]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/**
 * CRUD for the admin-provisioned Membership module, talking to Kinetix's
 * `members` endpoints. The route prefix (incl. any team segment) comes from the
 * shared `kinetix_config`. `assignableRoles` is the server-enforced allow-list —
 * the only roles a provisioner may hand out.
 */
export function useKinetixMembers() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/members`;

    const provisions = ref<KinetixMemberProvision[]>([]);
    const assignableRoles = ref<string[]>([]);
    const loading = ref(false);

    async function load(): Promise<void> {
        loading.value = true;

        try {
            const data = await kinetixFetch<MembersResponse>(base());

            provisions.value = data?.provisions ?? [];
            assignableRoles.value = data?.assignable_roles ?? [];
        } finally {
            loading.value = false;
        }
    }

    async function provision(email: string, role: string): Promise<unknown> {
        return kinetixFetch(base(), { method: 'POST', body: { email, role } });
    }

    async function resend(member: KinetixMemberProvision): Promise<unknown> {
        return kinetixFetch(`${base()}/${member.id}/resend`, {
            method: 'POST',
        });
    }

    async function updateRole(
        member: KinetixMemberProvision,
        role: string,
    ): Promise<unknown> {
        return kinetixFetch(`${base()}/${member.id}`, {
            method: 'PUT',
            body: { role },
        });
    }

    async function revoke(member: KinetixMemberProvision): Promise<unknown> {
        return kinetixFetch(`${base()}/${member.id}`, { method: 'DELETE' });
    }

    return {
        provisions,
        assignableRoles,
        loading,
        load,
        provision,
        resend,
        updateRole,
        revoke,
    };
}
