<script setup lang="ts">
import { Eye, EyeOff, Lock, Pencil, Plus, X } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import {
    notificationRoutingMessage,
    useKinetixNotificationRouting,
} from '@/composables/useKinetixNotificationRouting';
import type {
    KinetixNotificationAudienceType,
    KinetixNotificationRoute,
    KinetixNotificationRoutingType,
} from '@/types/kinetix';
import KinetixAlert from './KinetixAlert.vue';
import KinetixButton from './KinetixButton.vue';
import KinetixConfirmModal from './KinetixConfirmModal.vue';
import KinetixEmptyState from './KinetixEmptyState.vue';
import KinetixNotificationChannelIcons from './NotificationRouting/KinetixNotificationChannelIcons.vue';
import KinetixNotificationRecipientsPreview from './NotificationRouting/KinetixNotificationRecipientsPreview.vue';
import KinetixNotificationRouteDialog from './NotificationRouting/KinetixNotificationRouteDialog.vue';
import { notificationAudienceIcon } from './NotificationRouting/notificationRoutingIcons';
import { safeIdPart } from './NotificationRouting/notificationRoutingIds';
import KinetixBadge from './primitives/KinetixBadge.vue';

/**
 * Drop-in routing screen for a team: a section per notification type, each
 * with the audiences it goes to (a member, a role, a group, or everyone who
 * can see it) and the channels used for each. Add an audience, change its
 * channels, remove it, or preview who a type would reach right now. A type's
 * mandatory channels (set in code) are only ever shown, never editable.
 * Mount it behind the `notification_routing.manage` ability.
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
    types,
    channels,
    audiences,
    loading,
    loaded,
    loadFailed,
    previewType,
    preview,
    previewLoading,
    previewFailed,
    load,
    loadAudiences,
    searchMembers,
    addRoute,
    updateRoute,
    removeRoute,
    previewRecipients,
    closePreview,
} = useKinetixNotificationRouting();

const channelLabels = computed<Record<string, string>>(() =>
    Object.fromEntries(channels.value.map((c) => [c.key, c.label])),
);

const dialogOpen = ref(false);
const dialogType = ref<KinetixNotificationRoutingType | null>(null);
const dialogRoute = ref<KinetixNotificationRoute | null>(null);

const removing = ref<{
    type: KinetixNotificationRoutingType;
    route: KinetixNotificationRoute;
} | null>(null);
const removeOpen = ref(false);
const removeBusy = ref(false);

/** Rules and people load together: the picker's people never wait for the rules. */
async function loadAll(): Promise<void> {
    await Promise.all([
        load(),
        // A routing screen never lists the members (its member picker
        // searches the server), only the roles and groups.
        loadAudiences({ members: false, force: true }).catch(() => {
            // The dialog explains an empty list.
        }),
    ]);
}

onMounted(loadAll);

// A group was created, renamed or deleted (here or on the groups screen): who
// an open preview reaches may have changed.
watch(
    () => audiences.value.groups,
    () => {
        if (previewType.value) {
            void previewRecipients(previewType.value);
        }
    },
);

const labelsOf = (keys: string[]): string =>
    keys.map((key) => channelLabels.value[key] ?? key).join(', ');

const mandatoryOf = (type: KinetixNotificationRoutingType): string[] =>
    type.mandatory ?? [];

const kindLabel = (kind: KinetixNotificationAudienceType): string =>
    t(`kinetix.notification_routing_kind_${kind}`);

/** The name a rule shows: the audience's own, or the "everyone" label for `eligible`. */
const audienceName = (route: KinetixNotificationRoute): string =>
    route.audienceType === 'eligible'
        ? t('kinetix.notification_routing_kind_eligible')
        : (route.audienceLabel ?? '');

function openAdd(type: KinetixNotificationRoutingType): void {
    dialogType.value = type;
    dialogRoute.value = null;
    dialogOpen.value = true;
}

function openEdit(
    type: KinetixNotificationRoutingType,
    route: KinetixNotificationRoute,
): void {
    dialogType.value = type;
    dialogRoute.value = route;
    dialogOpen.value = true;
}

async function save(input: {
    audienceType: KinetixNotificationAudienceType;
    audienceId: string | null;
    channels: string[];
}): Promise<void> {
    const type = dialogType.value;

    if (!type) {
        return;
    }

    if (dialogRoute.value) {
        await updateRoute(dialogRoute.value, input.channels);
    } else {
        await addRoute({
            type: type.key,
            audience_type: input.audienceType,
            ...(input.audienceType === 'eligible'
                ? {}
                : { audience_id: input.audienceId }),
            channels: input.channels,
        });
    }

    toast.success(t('kinetix.notification_routing_saved'));
    refreshPreview(type.key);
}

/** The rules of a type changed: an open preview of it is out of date. */
function refreshPreview(typeKey: string): void {
    if (previewType.value === typeKey) {
        void previewRecipients(typeKey);
    }
}

function askRemove(
    type: KinetixNotificationRoutingType,
    route: KinetixNotificationRoute,
): void {
    removing.value = { type, route };
    removeOpen.value = true;
}

async function confirmRemove(): Promise<void> {
    const target = removing.value;

    if (!target) {
        return;
    }

    removeBusy.value = true;

    try {
        await removeRoute(target.route);
        removeOpen.value = false;
        toast.success(t('kinetix.notification_routing_removed_toast'));
        refreshPreview(target.type.key);
        // The row that held focus is gone: hand it to the type's own button.
        await nextTick();
        document.getElementById(idFor(target.type, 'add'))?.focus();
    } catch (e) {
        toast.error(
            notificationRoutingMessage(
                e,
                t('kinetix.notification_routing_failed'),
            ),
        );
    } finally {
        removeBusy.value = false;
    }
}

function togglePreview(type: KinetixNotificationRoutingType): void {
    if (previewType.value === type.key) {
        closePreview();

        return;
    }

    void previewRecipients(type.key);
}

/** The ✕ inside the panel: the focused control goes away, so focus returns to the type's button. */
async function closePreviewFor(
    type: KinetixNotificationRoutingType,
): Promise<void> {
    closePreview();
    await nextTick();
    document.getElementById(idFor(type, 'previewbtn'))?.focus();
}

// DOM ids: a per-instance uid plus the type key with anything an id (or a
// selector) would choke on replaced, so two screens never collide.
const uid = useId();
const idFor = (type: KinetixNotificationRoutingType, part: string): string =>
    `${uid}-${safeIdPart(type.key)}-${part}`;
</script>

<template>
    <div class="space-y-4" data-test="notification-routing">
        <div v-if="!hideTitle" class="space-y-1">
            <h2 class="text-lg font-semibold text-foreground">
                {{ t('kinetix.notification_routing_title') }}
            </h2>
            <p class="text-sm text-muted-foreground">
                {{ t('kinetix.notification_routing_description') }}
            </p>
        </div>

        <div
            v-if="loading && !loaded"
            class="space-y-3"
            role="status"
            :aria-label="t('kinetix.notification_routing_loading')"
        >
            <div
                v-for="n in 3"
                :key="n"
                class="h-24 animate-pulse rounded-lg border border-border bg-muted/40"
            />
        </div>

        <KinetixAlert
            v-else-if="loadFailed && !loaded"
            color="danger"
            :description="t('kinetix.notification_routing_load_failed')"
        >
            <template #actions>
                <KinetixButton variant="outline" size="sm" @click="loadAll">
                    {{ t('kinetix.notification_routing_retry') }}
                </KinetixButton>
            </template>
        </KinetixAlert>

        <KinetixEmptyState
            v-else-if="types.length === 0"
            icon="mail"
            :title="t('kinetix.notification_routing_empty_title')"
            :description="t('kinetix.notification_routing_empty_description')"
        />

        <ul v-else class="space-y-3">
            <li
                v-for="type in types"
                :key="type.key"
                class="rounded-lg overflow-hidden border border-border bg-card text-card-foreground"
                :data-test="`routing-type-${type.key}`"
            >
                <section :aria-labelledby="idFor(type, 'title')">
                    <header
                        class="gap-x-4 gap-y-2 px-4 py-3 sm:flex-row sm:items-start sm:justify-between flex flex-col"
                    >
                        <div class="min-w-0 space-y-1">
                            <h3
                                :id="idFor(type, 'title')"
                                class="text-sm font-semibold text-foreground"
                            >
                                {{ type.label }}
                            </h3>
                            <p
                                class="font-mono text-xs break-all text-muted-foreground"
                            >
                                {{ type.key }}
                            </p>
                            <div
                                v-if="mandatoryOf(type).length > 0"
                                class="gap-x-2 gap-y-1 pt-1 flex flex-wrap items-center"
                                data-test="routing-mandatory"
                            >
                                <KinetixBadge color="warning">
                                    <Lock
                                        class="mr-1 size-3"
                                        aria-hidden="true"
                                    />
                                    {{
                                        t(
                                            'kinetix.notification_routing_mandatory',
                                        )
                                    }}:
                                    {{ labelsOf(mandatoryOf(type)) }}
                                </KinetixBadge>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        t(
                                            'kinetix.notification_routing_mandatory_hint',
                                            {
                                                channels: labelsOf(
                                                    mandatoryOf(type),
                                                ),
                                            },
                                        )
                                    }}
                                </p>
                            </div>
                        </div>

                        <div class="gap-2 flex shrink-0 flex-wrap items-center">
                            <KinetixButton
                                variant="outline"
                                size="sm"
                                :id="idFor(type, 'previewbtn')"
                                :aria-expanded="previewType === type.key"
                                :aria-controls="
                                    previewType === type.key
                                        ? idFor(type, 'preview')
                                        : undefined
                                "
                                data-test="routing-preview"
                                @click="togglePreview(type)"
                            >
                                <template #icon>
                                    <EyeOff
                                        v-if="previewType === type.key"
                                        aria-hidden="true"
                                    />
                                    <Eye v-else aria-hidden="true" />
                                </template>
                                {{
                                    previewType === type.key
                                        ? t(
                                              'kinetix.notification_routing_hide_preview',
                                          )
                                        : t(
                                              'kinetix.notification_routing_preview',
                                          )
                                }}
                            </KinetixButton>
                            <KinetixButton
                                :id="idFor(type, 'add')"
                                size="sm"
                                data-test="routing-add"
                                @click="openAdd(type)"
                            >
                                <template #icon>
                                    <Plus aria-hidden="true" />
                                </template>
                                {{ t('kinetix.notification_routing_add') }}
                            </KinetixButton>
                        </div>
                    </header>

                    <p
                        v-if="type.routes.length === 0"
                        class="px-4 py-3 text-sm border-t border-border text-muted-foreground"
                    >
                        {{
                            type.fallback ||
                            t(
                                type.hasFallback
                                    ? 'kinetix.notification_routing_no_routes_fallback'
                                    : 'kinetix.notification_routing_no_routes',
                            )
                        }}
                    </p>

                    <ul
                        v-else
                        class="divide-y divide-border border-t border-border"
                    >
                        <li
                            v-for="route in type.routes"
                            :key="route.id"
                            class="gap-x-3 gap-y-2 px-4 py-2.5 sm:flex-row sm:items-center sm:justify-between flex flex-col"
                            :data-test="`routing-route-${route.id}`"
                        >
                            <div class="gap-2 min-w-0 flex items-center">
                                <component
                                    :is="
                                        notificationAudienceIcon(
                                            route.audienceType,
                                        )
                                    "
                                    class="size-4 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span class="sr-only"
                                    >{{ kindLabel(route.audienceType) }}:</span
                                >
                                <span
                                    v-if="
                                        route.audienceLabel !== null ||
                                        route.audienceType === 'eligible'
                                    "
                                    class="text-sm min-w-0 truncate text-foreground"
                                    :title="audienceName(route)"
                                    >{{ audienceName(route) }}</span
                                >
                                <template v-else>
                                    <KinetixBadge color="warning">{{
                                        t(
                                            'kinetix.notification_routing_removed',
                                        )
                                    }}</KinetixBadge>
                                    <span
                                        class="text-xs text-muted-foreground"
                                        >{{
                                            t(
                                                'kinetix.notification_routing_removed_hint',
                                            )
                                        }}</span
                                    >
                                </template>
                            </div>

                            <div
                                class="gap-2 sm:justify-end flex items-center justify-between"
                            >
                                <KinetixNotificationChannelIcons
                                    :channels="route.channels"
                                    :labels="channelLabels"
                                />
                                <div class="flex items-center">
                                    <KinetixButton
                                        variant="ghost"
                                        size="icon-sm"
                                        :aria-label="
                                            t(
                                                'kinetix.notification_routing_edit_channels',
                                                {
                                                    name:
                                                        audienceName(route) ||
                                                        kindLabel(
                                                            route.audienceType,
                                                        ),
                                                },
                                            )
                                        "
                                        data-test="routing-edit"
                                        @click="openEdit(type, route)"
                                    >
                                        <Pencil aria-hidden="true" />
                                    </KinetixButton>
                                    <KinetixButton
                                        variant="ghost"
                                        size="icon-sm"
                                        :aria-label="
                                            t(
                                                'kinetix.notification_routing_remove_audience',
                                                {
                                                    name:
                                                        audienceName(route) ||
                                                        kindLabel(
                                                            route.audienceType,
                                                        ),
                                                },
                                            )
                                        "
                                        data-test="routing-remove"
                                        @click="askRemove(type, route)"
                                    >
                                        <X aria-hidden="true" />
                                    </KinetixButton>
                                </div>
                            </div>
                        </li>
                    </ul>

                    <KinetixNotificationRecipientsPreview
                        v-if="previewType === type.key"
                        :id="idFor(type, 'preview')"
                        :title="type.label"
                        :preview="preview"
                        :loading="previewLoading"
                        :failed="previewFailed"
                        :channel-labels="channelLabels"
                        :fallback="type.fallback"
                        :has-fallback="type.hasFallback"
                        @close="closePreviewFor(type)"
                    />
                </section>
            </li>
        </ul>

        <KinetixNotificationRouteDialog
            v-model:open="dialogOpen"
            :type="dialogType"
            :route="dialogRoute"
            :audiences="audiences"
            :channel-labels="channelLabels"
            :search-members="searchMembers"
            :save="save"
        />

        <KinetixConfirmModal
            v-model:open="removeOpen"
            :heading="t('kinetix.notification_routing_remove_title')"
            :description="
                removing
                    ? t('kinetix.notification_routing_remove_description', {
                          name:
                              audienceName(removing.route) ||
                              kindLabel(removing.route.audienceType),
                          type: removing.type.label,
                      })
                    : null
            "
            :submit-label="t('kinetix.notification_routing_remove')"
            :processing="removeBusy"
            @confirm="confirmRemove"
        />
    </div>
</template>
