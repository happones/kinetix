<script setup lang="ts">
import { Lock } from '@lucide/vue';
import { onMounted, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixNotificationPreferences } from '@/composables/useKinetixNotificationPreferences';
import KinetixCheckbox from './KinetixCheckbox.vue';
import { safeIdPart } from './NotificationRouting/notificationRoutingIds';

/**
 * Drop-in notification-preferences matrix: a row per notification type and a
 * column per delivery channel, each cell a checkbox — or a dash where the
 * type isn't sent on that channel. Toggling a cell persists immediately.
 * Mount on an account / settings page.
 */
withDefaults(
    defineProps<{
        /**
         * Skip the built-in heading — for a page that already titles the
         * section, where it said the same thing twice.
         */
        hideTitle?: boolean;
    }>(),
    { hideTitle: false },
);

const { t } = useI18n();
const uid = useId();
const hintId = (key: string): string => `${uid}-${safeIdPart(key)}-mandatory`;

const channelLabel = (key: string): string =>
    matrix.value.channels.find((c) => c.key === key)?.label ?? key;

/** The mandatory channels of a type, by label: what the visible hint names. */
const mandatoryLabels = (keys: string[] | undefined): string =>
    (keys ?? []).map(channelLabel).join(', ');
const { matrix, loading, load, set, isMandatory } =
    useKinetixNotificationPreferences();

onMounted(load);
</script>

<template>
    <div class="space-y-4">
        <div v-if="!hideTitle" class="space-y-1">
            <h2 class="text-lg font-semibold text-foreground">
                {{ t('kinetix.notification_prefs_title') }}
            </h2>
            <p class="text-sm text-muted-foreground">
                {{ t('kinetix.notification_prefs_description') }}
            </p>
        </div>

        <p
            v-if="!loading && matrix.types.length === 0"
            class="text-sm text-muted-foreground"
        >
            {{ t('kinetix.notification_prefs_empty') }}
        </p>

        <div v-else class="rounded-lg overflow-x-auto border border-border">
            <table class="text-sm w-full">
                <thead>
                    <tr class="border-b border-border bg-muted/40">
                        <th
                            class="px-4 py-3 font-medium text-left text-muted-foreground"
                        >
                            {{ t('kinetix.notification_prefs_type') }}
                        </th>
                        <th
                            v-for="channel in matrix.channels"
                            :key="channel.key"
                            class="px-4 py-3 font-medium text-center text-muted-foreground"
                        >
                            {{ channel.label }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="type in matrix.types"
                        :key="type.key"
                        class="border-b border-border last:border-0"
                    >
                        <td class="px-4 py-3 font-medium text-foreground">
                            {{ type.label }}
                            <p
                                v-if="type.mandatory?.length"
                                :id="hintId(type.key)"
                                class="mt-0.5 gap-1 text-xs font-normal flex items-start text-muted-foreground"
                                data-test="pref-mandatory-hint"
                            >
                                <Lock
                                    class="mt-0.5 size-3 shrink-0"
                                    aria-hidden="true"
                                />
                                <span>{{
                                    t(
                                        'kinetix.notification_routing_prefs_required_on',
                                        {
                                            channels: mandatoryLabels(
                                                type.mandatory,
                                            ),
                                        },
                                    )
                                }}</span>
                            </p>
                        </td>
                        <td
                            v-for="channel in matrix.channels"
                            :key="channel.key"
                            class="px-4 py-3 text-center"
                        >
                            <div
                                v-if="channel.key in type.channels"
                                class="flex justify-center"
                            >
                                <KinetixCheckbox
                                    :checked="type.channels[channel.key]"
                                    :aria-disabled="
                                        isMandatory(type.key, channel.key)
                                            ? 'true'
                                            : undefined
                                    "
                                    :class="
                                        isMandatory(type.key, channel.key)
                                            ? 'cursor-not-allowed opacity-50'
                                            : undefined
                                    "
                                    :aria-label="`${type.label} · ${channel.label}`"
                                    :aria-describedby="
                                        isMandatory(type.key, channel.key)
                                            ? hintId(type.key)
                                            : undefined
                                    "
                                    :title="
                                        isMandatory(type.key, channel.key)
                                            ? t(
                                                  'kinetix.notification_preference_mandatory',
                                              )
                                            : undefined
                                    "
                                    @change="
                                        set(
                                            type.key,
                                            channel.key,
                                            $event as boolean,
                                        )
                                    "
                                />
                            </div>
                            <template v-else>
                                <span
                                    aria-hidden="true"
                                    class="text-muted-foreground"
                                    >—</span
                                >
                                <span class="sr-only">{{
                                    t('kinetix.notification_prefs_not_sent')
                                }}</span>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
