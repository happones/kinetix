<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { roleLabel, useKinetixMembers } from '@/composables/useKinetixMembers';
import { statusBadgeClass } from '@/composables/useKinetixStatusColor';
import type { KinetixStatusColor } from '@/composables/useKinetixStatusColor';
import type { KinetixMemberProvision } from '@/types/kinetix';
import KinetixButton from './KinetixButton.vue';
import KinetixConfirmModal from './KinetixConfirmModal.vue';
import KinetixMemberProvisioner from './KinetixMemberProvisioner.vue';
import KinetixSelect from './KinetixSelect.vue';

/**
 * Drop-in members directory for the admin-provisioned onboarding model — the
 * substitute for the starter-kit's team-invitation screen. Lists provisioned
 * members (pending / active / revoked) with resend, role change and revoke, and
 * embeds the provisioning form. Place behind the `members.provision` ability.
 */
const {
    provisions,
    assignableRoles,
    loading,
    load,
    provision,
    resend,
    updateRole,
    revoke,
} = useKinetixMembers();
const { t } = useI18n();

const rowKey = (member: KinetixMemberProvision): string | number =>
    member.id ?? member.email;

/** KinetixSelect expects a `{ value: label }` record. */
const roleOptions = computed<Record<string, string>>(() =>
    Object.fromEntries(assignableRoles.value.map((r) => [r, roleLabel(r)])),
);

const STATUS_COLOR: Record<
    KinetixMemberProvision['status'],
    KinetixStatusColor
> = {
    pending: 'warning',
    active: 'success',
    revoked: 'gray',
};

onMounted(load);

// --- Pending state -------------------------------------------------------
// One action at a time: the busy row shows a spinner on the clicked control,
// every other action control disables — same contract as table actions.
const busyKey = ref<string | number | null>(null);
const busyKind = ref<'provision' | 'resend' | 'role' | 'revoke' | null>(null);
const isBusy = computed(() => busyKind.value !== null);

const isRowBusy = (
    member: KinetixMemberProvision,
    kind: 'resend' | 'role' | 'revoke',
): boolean => busyKind.value === kind && busyKey.value === rowKey(member);

async function run(
    kind: 'provision' | 'resend' | 'role' | 'revoke',
    key: string | number | null,
    action: () => Promise<unknown>,
    successMessage: string,
    errorMessage: string,
): Promise<void> {
    if (isBusy.value) {
        return;
    }

    busyKind.value = kind;
    busyKey.value = key;

    try {
        await action();
        await load();
        toast.success(successMessage);
    } catch (e) {
        toast.error(e instanceof Error && e.message ? e.message : errorMessage);
    } finally {
        busyKind.value = null;
        busyKey.value = null;
    }
}

function onProvision(email: string, role: string): void {
    void run(
        'provision',
        null,
        () => provision(email, role),
        t('kinetix.member_provisioned'),
        t('kinetix.member_provision_failed'),
    );
}

function onResend(member: KinetixMemberProvision): void {
    void run(
        'resend',
        rowKey(member),
        () => resend(member),
        t('kinetix.member_provisioned'),
        t('kinetix.member_provision_failed'),
    );
}

function onRoleChange(member: KinetixMemberProvision, role: string): void {
    void run(
        'role',
        rowKey(member),
        () => updateRole(member, role),
        t('kinetix.member_role_updated'),
        t('kinetix.save_failed'),
    );
}

// --- Revoke (destructive → confirm first) ---------------------------------
const revokeTarget = ref<KinetixMemberProvision | null>(null);
const isRevokeOpen = ref(false);

function requestRevoke(member: KinetixMemberProvision): void {
    if (isBusy.value) {
        return;
    }

    revokeTarget.value = member;
    isRevokeOpen.value = true;
}

async function confirmRevoke(): Promise<void> {
    const member = revokeTarget.value;

    if (!member) {
        return;
    }

    await run(
        'revoke',
        rowKey(member),
        () => revoke(member),
        t('kinetix.member_revoked'),
        t('kinetix.delete_failed'),
    );

    isRevokeOpen.value = false;
    revokeTarget.value = null;
}

function cancelRevoke(): void {
    if (isBusy.value) {
        return;
    }

    isRevokeOpen.value = false;
    revokeTarget.value = null;
}

// --- Client-side search + bounded rendering --------------------------------
const searchQuery = ref('');
const PAGE_SIZE = 25;
const visibleCount = ref(PAGE_SIZE);

const filteredProvisions = computed<KinetixMemberProvision[]>(() => {
    const query = searchQuery.value.trim().toLowerCase();

    if (!query) {
        return provisions.value;
    }

    return provisions.value.filter(
        (member) =>
            member.email.toLowerCase().includes(query) ||
            (member.name ?? '').toLowerCase().includes(query),
    );
});

const visibleProvisions = computed(() =>
    filteredProvisions.value.slice(0, visibleCount.value),
);

const hasMore = computed(
    () => filteredProvisions.value.length > visibleCount.value,
);

function statusLabel(status: KinetixMemberProvision['status']): string {
    return t(`kinetix.member_status_${status}`);
}
</script>

<template>
    <div class="space-y-4">
        <h2 class="text-lg font-semibold text-foreground">
            {{ t('kinetix.members_title') }}
        </h2>

        <div class="rounded-lg p-4 border border-border bg-card">
            <KinetixMemberProvisioner
                :assignable-roles="assignableRoles"
                :submitting="busyKind === 'provision'"
                @submit="onProvision"
            />
        </div>

        <div
            class="rounded-lg divide-y divide-border border border-border bg-card"
        >
            <div v-if="provisions.length > PAGE_SIZE" class="p-3">
                <input
                    v-model="searchQuery"
                    type="search"
                    :placeholder="t('kinetix.member_search')"
                    :aria-label="t('kinetix.member_search')"
                    class="px-3 py-2 text-sm w-full rounded-md border border-border bg-muted/40 text-foreground outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
            </div>

            <!-- Loading skeleton (first paint) -->
            <template v-if="loading && provisions.length === 0">
                <div
                    v-for="i in 3"
                    :key="`skeleton-${i}`"
                    class="gap-2 p-3 flex items-center justify-between"
                    aria-hidden="true"
                >
                    <div class="space-y-2 min-w-0 flex-1">
                        <div
                            class="h-4 w-40 animate-pulse rounded max-w-full bg-muted"
                        />
                        <div
                            class="h-3 w-56 animate-pulse rounded max-w-full bg-muted"
                        />
                    </div>
                    <div class="h-9 w-40 animate-pulse rounded bg-muted" />
                </div>
            </template>

            <p
                v-else-if="filteredProvisions.length === 0"
                class="p-4 text-sm text-muted-foreground"
            >
                {{ t('kinetix.no_members') }}
            </p>

            <div
                v-for="member in visibleProvisions"
                :key="rowKey(member)"
                class="gap-2 p-3 flex flex-wrap items-center justify-between"
            >
                <div class="min-w-0 basis-48 flex-1">
                    <p class="text-sm font-medium truncate text-foreground">
                        {{ member.name ?? member.email }}
                    </p>
                    <p
                        v-if="member.name"
                        class="text-xs truncate text-muted-foreground"
                    >
                        {{ member.email }}
                    </p>
                </div>

                <div class="gap-2 flex flex-wrap items-center">
                    <span
                        class="px-2 py-0.5 text-xs font-semibold inline-flex items-center rounded-full"
                        :class="statusBadgeClass(STATUS_COLOR[member.status])"
                    >
                        {{ statusLabel(member.status) }}
                    </span>

                    <!-- A revoked member's role is history, not an input: a role
                         change would silently re-grant it (the server rejects
                         this with a 422 too). -->
                    <span
                        v-if="member.status === 'revoked'"
                        class="text-xs text-muted-foreground"
                    >
                        {{ roleLabel(member.role) }}
                    </span>

                    <div v-else class="w-40">
                        <KinetixSelect
                            :value="member.role"
                            :options="roleOptions"
                            :disabled="isBusy"
                            :aria-label="`${t('kinetix.member_role')} — ${member.email}`"
                            @update:value="onRoleChange(member, $event)"
                        />
                    </div>

                    <KinetixButton
                        v-if="member.status === 'pending'"
                        variant="outline"
                        size="sm"
                        :disabled="isBusy"
                        :loading="isRowBusy(member, 'resend')"
                        :aria-label="`${t('kinetix.member_resend')} — ${member.email}`"
                        @click="onResend(member)"
                    >
                        {{ t('kinetix.member_resend') }}
                    </KinetixButton>

                    <KinetixButton
                        v-if="member.status !== 'revoked'"
                        variant="ghost"
                        size="sm"
                        :disabled="isBusy"
                        :loading="isRowBusy(member, 'revoke')"
                        :aria-label="`${t('kinetix.member_revoke')} — ${member.email}`"
                        @click="requestRevoke(member)"
                    >
                        {{ t('kinetix.member_revoke') }}
                    </KinetixButton>
                </div>
            </div>

            <div v-if="hasMore" class="p-3 text-center">
                <KinetixButton
                    variant="ghost"
                    size="sm"
                    @click="visibleCount += PAGE_SIZE"
                >
                    {{ t('kinetix.show_more') }}
                </KinetixButton>
            </div>
        </div>

        <KinetixConfirmModal
            v-model:open="isRevokeOpen"
            :heading="t('kinetix.member_revoke')"
            :description="
                t('kinetix.member_revoke_confirm', {
                    email: revokeTarget?.email ?? '',
                })
            "
            color="danger"
            :processing="busyKind === 'revoke'"
            @confirm="confirmRevoke"
            @cancel="cancelRevoke"
        />
    </div>
</template>
