import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type {
    KinetixMemberAbilities,
    KinetixMemberProvision,
    KinetixMemberRole,
    KinetixSharedProps,
} from '@/types/kinetix';

export type KinetixMemberIdentifier = 'email' | 'username' | 'phone';

interface MembersResponse {
    provisions: KinetixMemberProvision[];
    assignable_roles: string[];
    /** Absent from servers older than v0.262.0 — every reader defaults it. */
    roles?: KinetixMemberRole[];
    identifier_field?: KinetixMemberIdentifier;
    reserved_roles?: string[];
    can?: KinetixMemberAbilities;
}

const ALL_ABILITIES: KinetixMemberAbilities = {
    provision: true,
    update: true,
    revoke: true,
    credentials: true,
};

/**
 * Headline-case a role slug for display (`support-agent` → `Support Agent`).
 * Shared by every role select in the membership UI, so the provisioner and the
 * member list render the same labels on the same screen.
 */
export function roleLabel(name: string): string {
    return name.replace(/[-_]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/**
 * A role's display label: the app's own label when it gave one (roles from
 * the app's own logic), else the humanized name.
 */
export function memberRoleLabel(
    name: string,
    roles: KinetixMemberRole[] = [],
): string {
    return roles.find((role) => role.name === name)?.label || roleLabel(name);
}

/**
 * CRUD for the admin-provisioned Membership module, talking to Kinetix's
 * `members` endpoints. The route prefix (incl. any team segment) comes from the
 * shared `kinetix_config`. `assignableRoles` is the server-enforced allow-list —
 * the only roles a provisioner may hand out — and `roles` the same list with
 * each role's permission count. `reservedRoles` are never offered (the
 * super-admin belongs to the team owner); `can` says which actions the viewer
 * may take; `identifierField` is the field a member is provisioned by.
 */
export function useKinetixMembers() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/members`;

    const provisions = ref<KinetixMemberProvision[]>([]);
    const assignableRoles = ref<string[]>([]);
    const roles = ref<KinetixMemberRole[]>([]);
    const reservedRoles = ref<string[]>([]);
    const identifierField = ref<KinetixMemberIdentifier>('email');
    const can = ref<KinetixMemberAbilities>({ ...ALL_ABILITIES });
    const loading = ref(false);

    async function load(): Promise<void> {
        loading.value = true;

        try {
            const data = await kinetixFetch<MembersResponse>(base());

            provisions.value = data?.provisions ?? [];
            assignableRoles.value = data?.assignable_roles ?? [];
            roles.value =
                data?.roles ??
                assignableRoles.value.map((name) => ({
                    name,
                    permissions: null,
                    global: false,
                }));
            reservedRoles.value = data?.reserved_roles ?? [];
            identifierField.value = data?.identifier_field ?? 'email';
            can.value = { ...ALL_ABILITIES, ...data?.can };
        } finally {
            loading.value = false;
        }
    }

    /**
     * Provision a member. The identifier is sent under the field the server
     * provisions by (`email`, `username` or `phone`). The response carries the
     * credential to hand over when there is one — shown once, never again.
     */
    async function provision(
        identifier: string,
        role: string,
        name: string | null = null,
    ): Promise<KinetixMemberProvision | null> {
        return kinetixFetch<KinetixMemberProvision>(base(), {
            method: 'POST',
            body: {
                [identifierField.value]: identifier,
                role,
                ...(name ? { name } : {}),
            },
        });
    }

    /** Re-issue a member's temporary password or activation link. */
    async function issueCredential(
        member: KinetixMemberProvision,
    ): Promise<KinetixMemberProvision | null> {
        return kinetixFetch<KinetixMemberProvision>(
            `${base()}/${member.id}/credential`,
            { method: 'POST' },
        );
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
        roles,
        reservedRoles,
        identifierField,
        can,
        loading,
        load,
        provision,
        issueCredential,
        resend,
        updateRole,
        revoke,
    };
}
