<script setup lang="ts">
import { Pencil, Plus, Trash2, Users } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import type { KinetixNotificationGroupInput } from '@/composables/useKinetixNotificationRouting';
import {
    notificationRoutingMessage,
    useKinetixNotificationRouting,
} from '@/composables/useKinetixNotificationRouting';
import type { KinetixNotificationGroup } from '@/types/kinetix';
import KinetixAlert from './KinetixAlert.vue';
import KinetixButton from './KinetixButton.vue';
import KinetixConfirmModal from './KinetixConfirmModal.vue';
import KinetixEmptyState from './KinetixEmptyState.vue';
import KinetixNotificationGroupDialog from './NotificationRouting/KinetixNotificationGroupDialog.vue';

/**
 * Drop-in manager for notification groups: named sets of team members that
 * notification routing can send to ("Support leads"). Create, rename, change
 * who is in one, or delete it (its routing rules go with it). Mount it behind
 * the `notification_routing.manage` ability, next to <KinetixNotificationRouting>.
 */
withDefaults(
    defineProps<{
        /** Skip the built-in heading, for a page that already titles the section. */
        hideTitle?: boolean;
    }>(),
    { hideTitle: false },
);

const { t } = useI18n();
const {
    groups,
    audiences,
    groupsLoading: loading,
    groupsLoaded: loaded,
    groupsLoadFailed: loadFailed,
    loadGroups,
    loadAudiences,
    searchMembers,
    createGroup,
    updateGroup,
    deleteGroup,
} = useKinetixNotificationRouting();

const newButtonId = `${useId()}-new-group`;
const dialogOpen = ref(false);
const editing = ref<KinetixNotificationGroup | null>(null);
const deleting = ref<KinetixNotificationGroup | null>(null);
const deleteOpen = ref(false);
const deleteBusy = ref(false);

/** Groups and the team's people (the first 200) load together. */
async function loadAll(): Promise<void> {
    await Promise.all([
        loadGroups(),
        loadAudiences().catch(() => {
            // The picker then lists nobody; the group can still be named.
        }),
    ]);
}

onMounted(loadAll);

/** The member picker searches the server only when the list above is not the whole team. */
const searchOnServer = computed(() => !audiences.value.membersComplete);

function openCreate(): void {
    editing.value = null;
    dialogOpen.value = true;
}

function openEdit(group: KinetixNotificationGroup): void {
    editing.value = group;
    dialogOpen.value = true;
}

async function save(input: KinetixNotificationGroupInput): Promise<void> {
    if (editing.value) {
        await updateGroup(editing.value, input);
    } else {
        await createGroup(input);
    }

    toast.success(t('kinetix.notification_routing_groups_saved'));
}

function askDelete(group: KinetixNotificationGroup): void {
    deleting.value = group;
    deleteOpen.value = true;
}

async function confirmDelete(): Promise<void> {
    const group = deleting.value;

    if (!group) {
        return;
    }

    deleteBusy.value = true;

    try {
        await deleteGroup(group);
        deleteOpen.value = false;
        toast.success(t('kinetix.notification_routing_groups_deleted'));
        // The row that held focus is gone: hand it to the New group button.
        await nextTick();
        document.getElementById(newButtonId)?.focus();
    } catch (e) {
        toast.error(
            notificationRoutingMessage(
                e,
                t('kinetix.notification_routing_failed'),
            ),
        );
    } finally {
        deleteBusy.value = false;
    }
}
</script>

<template>
    <div class="space-y-4" data-test="notification-groups">
        <div class="gap-3 flex flex-wrap items-start justify-between">
            <div v-if="!hideTitle" class="space-y-1">
                <h2 class="text-lg font-semibold text-foreground">
                    {{ t('kinetix.notification_routing_groups_title') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ t('kinetix.notification_routing_groups_description') }}
                </p>
            </div>
            <KinetixButton
                :id="newButtonId"
                class="ml-auto"
                data-test="group-new"
                @click="openCreate"
            >
                <template #icon><Plus aria-hidden="true" /></template>
                {{ t('kinetix.notification_routing_groups_new') }}
            </KinetixButton>
        </div>

        <div
            v-if="loading && !loaded"
            class="space-y-2"
            role="status"
            :aria-label="t('kinetix.notification_routing_loading')"
        >
            <div
                v-for="n in 3"
                :key="n"
                class="h-16 animate-pulse rounded-lg border border-border bg-muted/40"
            />
        </div>

        <KinetixAlert
            v-else-if="loadFailed && !loaded"
            color="danger"
            :description="t('kinetix.notification_routing_groups_load_failed')"
        >
            <template #actions>
                <KinetixButton variant="outline" size="sm" @click="loadAll">
                    {{ t('kinetix.notification_routing_retry') }}
                </KinetixButton>
            </template>
        </KinetixAlert>

        <KinetixEmptyState
            v-else-if="groups.length === 0"
            icon="users"
            :title="t('kinetix.notification_routing_groups_empty_title')"
            :description="
                t('kinetix.notification_routing_groups_empty_description')
            "
        >
            <KinetixButton variant="outline" size="sm" @click="openCreate">
                <template #icon><Plus aria-hidden="true" /></template>
                {{ t('kinetix.notification_routing_groups_new') }}
            </KinetixButton>
        </KinetixEmptyState>

        <ul
            v-else
            class="rounded-lg divide-y divide-border overflow-hidden border border-border bg-card"
        >
            <li
                v-for="group in groups"
                :key="group.id"
                class="gap-x-3 gap-y-2 px-4 py-3 sm:flex-row sm:items-center sm:justify-between flex flex-col"
                :data-test="`group-${group.id}`"
            >
                <div class="gap-3 min-w-0 flex items-start">
                    <span
                        class="mt-0.5 size-8 flex shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
                    >
                        <Users class="size-4" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 space-y-0.5">
                        <p
                            class="text-sm font-medium text-foreground"
                            :title="group.name"
                        >
                            {{ group.name }}
                        </p>
                        <p
                            v-if="group.description"
                            class="text-xs text-muted-foreground"
                        >
                            {{ group.description }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{
                                group.members.length === 0
                                    ? t(
                                          'kinetix.notification_routing_groups_no_members',
                                      )
                                    : t(
                                          'kinetix.notification_routing_groups_member_count',
                                          {
                                              count: group.members.length,
                                          },
                                      )
                            }}
                        </p>
                    </div>
                </div>
                <div class="sm:self-auto flex items-center self-end">
                    <KinetixButton
                        variant="ghost"
                        size="icon-sm"
                        :aria-label="
                            t('kinetix.notification_routing_groups_edit', {
                                name: group.name,
                            })
                        "
                        data-test="group-edit"
                        @click="openEdit(group)"
                    >
                        <Pencil aria-hidden="true" />
                    </KinetixButton>
                    <KinetixButton
                        variant="ghost"
                        size="icon-sm"
                        :aria-label="
                            t('kinetix.notification_routing_groups_delete', {
                                name: group.name,
                            })
                        "
                        data-test="group-delete"
                        @click="askDelete(group)"
                    >
                        <Trash2 aria-hidden="true" />
                    </KinetixButton>
                </div>
            </li>
        </ul>

        <KinetixNotificationGroupDialog
            v-model:open="dialogOpen"
            :group="editing"
            :members="audiences.members"
            :search-members="searchOnServer ? searchMembers : undefined"
            :save="save"
        />

        <KinetixConfirmModal
            v-model:open="deleteOpen"
            :heading="t('kinetix.notification_routing_groups_delete_title')"
            :description="
                deleting
                    ? t(
                          'kinetix.notification_routing_groups_delete_description',
                          {
                              name: deleting.name,
                          },
                      )
                    : null
            "
            :submit-label="t('kinetix.delete')"
            :processing="deleteBusy"
            @confirm="confirmDelete"
        />
    </div>
</template>
