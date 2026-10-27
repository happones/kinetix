<script setup lang="ts">
import { KeyRound, Link2, Search, ShieldAlert } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import {
    memberRoleLabel,
    useKinetixMembers,
} from '@/composables/useKinetixMembers';
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import type { KinetixStatusColor } from '@/composables/useKinetixStatusColor';
import type { KinetixMemberProvision } from '@/types/kinetix';
import KinetixButton from './KinetixButton.vue';
import KinetixConfirmModal from './KinetixConfirmModal.vue';
import KinetixCopyableInput from './KinetixCopyableInput.vue';
import KinetixLabel from './KinetixLabel.vue';
import KinetixMemberProvisioner from './KinetixMemberProvisioner.vue';
import KinetixSelect from './KinetixSelect.vue';
import KinetixBadge from './primitives/KinetixBadge.vue';
import KinetixModal from './primitives/KinetixModal.vue';

/**
 * Drop-in members directory for the admin-provisioned onboarding model — the
 * substitute for the starter-kit's team-invitation screen. Lists provisioned
 * members (pending / active / revoked) with status filters, when each was
 * invited or joined, role change, resend, a fresh credential and revoke, and
 * embeds the provisioning form.
 *
 * Roles come from the server's allow-list: by default the Roles screen's
 * roles for this team, or whatever the app's own logic supplies
 * (`membership.assignable_roles`, with labels and descriptions). The
 * super-admin role is the team owner's and is never offered. Every action is
 * shown only when the viewer holds its `members.*` ability, and a credential
 * the server hands back (direct provisioning, manual delivery) is shown once,
 * ready to copy.
 */
const props = withDefaults(
    defineProps<{
        /**
         * Heading over the list. An app whose people already show in a
         * members list of its own names this "Invitations", which is what
         * the list holds: who was invited and whether they joined.
         */
        title?: string | null;
        /** Text when nobody has been invited. */
        emptyText?: string | null;
    }>(),
    { title: null, emptyText: null },
);

const {
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
} = useKinetixMembers();
const { t, locale } = useI18n();

const rowKey = (member: KinetixMemberProvision): string | number =>
    member.id ?? member.identifier ?? '';

const labelFor = (name: string): string => memberRoleLabel(name, roles.value);

/**
 * A member's role select. Their current role stays listed even when it is
 * no longer assignable (withheld from the list, or the owner's super-admin),
 * disabled — a blank select would hide what they hold today.
 */
function roleOptionsFor(
    member: KinetixMemberProvision,
): Record<string, string> {
    const options: Record<string, string> = Object.fromEntries(
        assignableRoles.value.map((name) => [name, labelFor(name)]),
    );

    if (!(member.role in options)) {
        options[member.role] = t('kinetix.member_role_not_assignable', {
            role: labelFor(member.role),
        });
    }

    return options;
}

const lockedRoles = (member: KinetixMemberProvision): string[] =>
    assignableRoles.value.includes(member.role) ? [] : [member.role];

const STATUS_COLOR: Record<
    KinetixMemberProvision['status'],
    KinetixStatusColor
> = {
    pending: 'warning',
    active: 'success',
    revoked: 'gray',
};

onMounted(load);

// --- Dates ------------------------------------------------------------------

function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '';
    }

    try {
        return new Intl.DateTimeFormat(locale.value, {
            dateStyle: 'medium',
        }).format(new Date(value));
    } catch {
        return new Date(value).toLocaleDateString();
    }
}

/** One line on where the member stands: joined, invited until, expired. */
function statusDetail(member: KinetixMemberProvision): string {
    if (member.status === 'active') {
        return member.activatedAt
            ? t('kinetix.member_joined', {
                  date: formatDate(member.activatedAt),
              })
            : '';
    }

    if (member.status === 'pending') {
        if (member.expired) {
            return t('kinetix.member_link_expired');
        }

        const invited = member.invitedAt
            ? t('kinetix.member_invited', {
                  date: formatDate(member.invitedAt),
              })
            : '';
        const expires = member.expiresAt
            ? t('kinetix.member_link_expires', {
                  date: formatDate(member.expiresAt),
              })
            : '';

        return [invited, expires].filter(Boolean).join(' · ');
    }

    return '';
}

function initials(member: KinetixMemberProvision): string {
    const source = (member.name ?? member.identifier ?? '?').trim();
    const words = source.split(/[\s@._-]+/).filter(Boolean);

    return (
        words
            .slice(0, 2)
            .map((word) => word[0])
            .join('') || '?'
    ).toUpperCase();
}

// --- Pending state -------------------------------------------------------
// One action at a time: the busy row shows a spinner on the clicked control,
// every other action control disables — same contract as table actions.
type BusyKind = 'provision' | 'resend' | 'role' | 'revoke' | 'credential';

const busyKey = ref<string | number | null>(null);
const busyKind = ref<BusyKind | null>(null);
const isBusy = computed(() => busyKind.value !== null);

const isRowBusy = (
    member: KinetixMemberProvision,
    kind: Exclude<BusyKind, 'provision'>,
): boolean => busyKind.value === kind && busyKey.value === rowKey(member);

async function run<T>(
    kind: BusyKind,
    key: string | number | null,
    action: () => Promise<T>,
    successMessage: string | ((result: T) => string),
    errorMessage: string,
): Promise<T | undefined> {
    if (isBusy.value) {
        return undefined;
    }

    busyKind.value = kind;
    busyKey.value = key;

    try {
        const result = await action();
        await load();
        toast.success(
            typeof successMessage === 'function'
                ? successMessage(result)
                : successMessage,
        );

        return result;
    } catch (e) {
        toast.error(e instanceof Error && e.message ? e.message : errorMessage);

        return undefined;
    } finally {
        busyKind.value = null;
        busyKey.value = null;
    }
}

// --- Credential hand-over (shown once) ---------------------------------------

const credential = ref<{
    member: string;
    type: 'password' | 'link';
    value: string;
    expiresAt: string | null;
} | null>(null);

function showCredential(
    result: KinetixMemberProvision | null | undefined,
): void {
    if (!result?.credential) {
        return;
    }

    credential.value = {
        member: result.name ?? result.identifier ?? '',
        ...result.credential,
    };
}

async function onProvision(
    identifier: string,
    role: string,
    name?: string,
): Promise<void> {
    const result = await run(
        'provision',
        null,
        () => provision(identifier, role, name ?? null),
        (created) =>
            created?.credential
                ? t('kinetix.member_added')
                : t('kinetix.member_provisioned'),
        t('kinetix.member_provision_failed'),
    );

    showCredential(result);
}

async function onIssueCredential(
    member: KinetixMemberProvision,
): Promise<void> {
    const result = await run(
        'credential',
        rowKey(member),
        () => issueCredential(member),
        t('kinetix.member_credential_issued'),
        t('kinetix.save_failed'),
    );

    showCredential(result);
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
    if (role === member.role) {
        return;
    }

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
        // A pending invitation was never a member: what ends is the invite.
        member.status === 'pending'
            ? t('kinetix.member_invite_cancelled')
            : t('kinetix.member_revoked'),
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

// --- Status filter + search + bounded rendering ----------------------------
type StatusFilter = 'all' | KinetixMemberProvision['status'];

const statusFilter = ref<StatusFilter>('all');
const STATUS_FILTERS: StatusFilter[] = ['all', 'active', 'pending', 'revoked'];

const statusCounts = computed<Record<StatusFilter, number>>(() => {
    const counts: Record<StatusFilter, number> = {
        all: provisions.value.length,
        active: 0,
        pending: 0,
        revoked: 0,
    };

    for (const member of provisions.value) {
        counts[member.status] += 1;
    }

    return counts;
});

/** Filters earn their place once there is something to filter. */
const showsTools = computed(() => provisions.value.length > 5);

const searchQuery = ref('');
const PAGE_SIZE = 25;
const visibleCount = ref(PAGE_SIZE);

const filteredProvisions = computed<KinetixMemberProvision[]>(() => {
    const query = searchQuery.value.trim().toLowerCase();

    return provisions.value.filter(
        (member) =>
            (statusFilter.value === 'all' ||
                member.status === statusFilter.value) &&
            (!query ||
                (member.identifier ?? '').toLowerCase().includes(query) ||
                (member.name ?? '').toLowerCase().includes(query) ||
                labelFor(member.role).toLowerCase().includes(query)),
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

function filterLabel(filter: StatusFilter): string {
    return filter === 'all'
        ? t('kinetix.member_filter_all')
        : statusLabel(filter);
}
</script>

<template>
    <div class="space-y-4">
        <h2 class="text-lg font-semibold text-foreground">
            {{ props.title ?? t('kinetix.members_title') }}
        </h2>

        <div
            v-if="can.provision"
            class="rounded-lg p-4 border border-border bg-card"
        >
            <KinetixMemberProvisioner
                :assignable-roles="assignableRoles"
                :roles="roles"
                :reserved-roles="reservedRoles"
                :identifier-type="identifierField"
                :submitting="busyKind === 'provision'"
                @submit="onProvision"
            />
        </div>

        <!-- A container: rows lay out by the card's width, not the
             viewport's, so a members card in a narrow column still reads. -->
        <div
            class="rounded-lg @container divide-y divide-border border border-border bg-card"
        >
            <div
                v-if="showsTools"
                class="gap-2 p-3 @xl:flex-row @xl:items-center flex flex-col"
            >
                <div
                    class="gap-1 flex flex-wrap"
                    role="group"
                    :aria-label="t('kinetix.member_filter_label')"
                >
                    <button
                        v-for="filter in STATUS_FILTERS"
                        :key="filter"
                        type="button"
                        class="gap-1.5 px-2.5 text-xs font-medium h-7 inline-flex items-center rounded-md border transition-colors"
                        :class="
                            statusFilter === filter
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border text-muted-foreground hover:bg-muted hover:text-foreground'
                        "
                        :aria-pressed="statusFilter === filter"
                        @click="
                            statusFilter = filter;
                            visibleCount = PAGE_SIZE;
                        "
                    >
                        {{ filterLabel(filter) }}
                        <span class="tabular-nums opacity-80">{{
                            statusCounts[filter]
                        }}</span>
                    </button>
                </div>
                <div class="min-w-0 @xl:ml-auto @xl:w-64 relative">
                    <Search
                        class="left-2.5 size-4 pointer-events-none absolute top-1/2 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <input
                        v-model="searchQuery"
                        type="search"
                        :placeholder="t('kinetix.member_search')"
                        :aria-label="t('kinetix.member_search')"
                        :class="[inputClass, 'h-8 pl-8']"
                    />
                </div>
            </div>

            <!-- Loading skeleton (first paint) -->
            <template v-if="loading && provisions.length === 0">
                <div
                    v-for="i in 3"
                    :key="`skeleton-${i}`"
                    class="gap-3 p-3 flex items-center"
                    aria-hidden="true"
                >
                    <div class="size-9 animate-pulse rounded-full bg-muted" />
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
                {{
                    provisions.length === 0
                        ? (props.emptyText ?? t('kinetix.no_members'))
                        : t('kinetix.member_no_matches')
                }}
            </p>

            <div
                v-for="member in visibleProvisions"
                :key="rowKey(member)"
                class="gap-2 p-3 @3xl:flex-row @3xl:items-center @3xl:justify-between @3xl:gap-3 flex flex-col"
                :class="member.status === 'revoked' ? 'opacity-70' : ''"
                data-test="member-row"
            >
                <div class="gap-3 min-w-0 flex flex-1 items-center">
                    <span
                        class="size-9 text-xs font-semibold flex shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
                        aria-hidden="true"
                        >{{ initials(member) }}</span
                    >
                    <div class="min-w-0">
                        <p class="text-sm font-medium truncate text-foreground">
                            {{ member.name ?? member.identifier }}
                        </p>
                        <p
                            v-if="member.name"
                            class="text-xs truncate text-muted-foreground"
                        >
                            {{ member.identifier }}
                        </p>
                        <p
                            v-if="statusDetail(member)"
                            class="text-xs truncate"
                            :class="
                                member.status === 'pending' && member.expired
                                    ? 'font-medium text-foreground'
                                    : 'text-muted-foreground'
                            "
                            data-test="member-status-detail"
                        >
                            {{ statusDetail(member) }}
                        </p>
                    </div>
                </div>

                <div
                    class="gap-2 @sm:pl-12 @3xl:pl-0 @3xl:justify-end flex flex-wrap items-center"
                >
                    <KinetixBadge :color="STATUS_COLOR[member.status]">
                        {{ statusLabel(member.status) }}
                    </KinetixBadge>

                    <!-- A revoked member's role is history, not an input: a role
                         change would silently re-grant it (the server rejects
                         this with a 422 too). Without `members.update` it is
                         read-only as well. -->
                    <span
                        v-if="member.status === 'revoked' || !can.update"
                        class="text-xs text-muted-foreground"
                        data-test="member-role-text"
                    >
                        {{ labelFor(member.role) }}
                    </span>

                    <div v-else class="w-48">
                        <KinetixSelect
                            :value="member.role"
                            :options="roleOptionsFor(member)"
                            :disabled-keys="lockedRoles(member)"
                            :disabled="isBusy"
                            :aria-label="`${t('kinetix.member_role')} — ${member.identifier}`"
                            @update:value="onRoleChange(member, $event)"
                        />
                    </div>

                    <KinetixButton
                        v-if="member.status === 'pending' && can.provision"
                        variant="outline"
                        size="sm"
                        :disabled="isBusy"
                        :loading="isRowBusy(member, 'resend')"
                        :aria-label="`${t('kinetix.member_resend')} — ${member.identifier}`"
                        @click="onResend(member)"
                    >
                        {{ t('kinetix.member_resend') }}
                    </KinetixButton>

                    <!-- A pending member gets a new activation link to pass
                         on; an active one a new temporary password. -->
                    <KinetixButton
                        v-if="member.status !== 'revoked' && can.credentials"
                        variant="ghost"
                        size="sm"
                        :disabled="isBusy"
                        :loading="isRowBusy(member, 'credential')"
                        :aria-label="`${
                            member.status === 'pending'
                                ? t('kinetix.member_new_link')
                                : t('kinetix.member_new_password')
                        } — ${member.identifier}`"
                        @click="onIssueCredential(member)"
                    >
                        <template #icon>
                            <Link2
                                v-if="member.status === 'pending'"
                                class="size-4"
                                aria-hidden="true"
                            />
                            <KeyRound
                                v-else
                                class="size-4"
                                aria-hidden="true"
                            />
                        </template>
                        <span class="@md:inline hidden">{{
                            member.status === 'pending'
                                ? t('kinetix.member_new_link')
                                : t('kinetix.member_new_password')
                        }}</span>
                    </KinetixButton>

                    <KinetixButton
                        v-if="member.status !== 'revoked' && can.revoke"
                        variant="ghost"
                        size="sm"
                        :disabled="isBusy"
                        :loading="isRowBusy(member, 'revoke')"
                        :aria-label="`${t('kinetix.member_revoke')} — ${member.identifier}`"
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
                t(
                    revokeTarget?.status === 'pending'
                        ? 'kinetix.member_revoke_pending_confirm'
                        : 'kinetix.member_revoke_confirm',
                    {
                        email:
                            revokeTarget?.name ??
                            revokeTarget?.identifier ??
                            '',
                    },
                )
            "
            :submit-label="t('kinetix.member_revoke')"
            color="danger"
            :processing="busyKind === 'revoke'"
            @confirm="confirmRevoke"
            @cancel="cancelRevoke"
        />

        <!-- The credential the server handed back — the only time it is
             readable. -->
        <KinetixModal
            :open="credential !== null"
            :title="
                t('kinetix.member_credential_title', {
                    name: credential?.member ?? '',
                })
            "
            :description="
                credential?.type === 'password'
                    ? t('kinetix.member_credential_password_hint')
                    : t('kinetix.member_credential_link_hint')
            "
            @update:open="(open: boolean) => !open && (credential = null)"
        >
            <div
                v-if="credential"
                class="space-y-3"
                data-test="member-credential"
            >
                <KinetixLabel for="kinetix-member-credential">
                    {{
                        credential.type === 'password'
                            ? t('kinetix.member_credential_password')
                            : t('kinetix.member_credential_link')
                    }}
                </KinetixLabel>
                <KinetixCopyableInput
                    id="kinetix-member-credential"
                    :value="credential.value"
                    copyable
                    :revealable="credential.type === 'password'"
                />
                <p
                    v-if="credential.expiresAt"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        t('kinetix.member_credential_expires', {
                            date: formatDate(credential.expiresAt),
                        })
                    }}
                </p>
                <p
                    class="gap-2 p-3 text-xs flex items-start rounded-md bg-muted text-muted-foreground"
                >
                    <ShieldAlert
                        class="size-4 mt-px shrink-0"
                        aria-hidden="true"
                    />
                    {{ t('kinetix.member_credential_once') }}
                </p>
            </div>
            <template #footer>
                <KinetixButton @click="credential = null">
                    {{ t('kinetix.member_credential_done') }}
                </KinetixButton>
            </template>
        </KinetixModal>
    </div>
</template>
