<script setup lang="ts">
import { onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixNotificationPreferences } from '@/composables/useKinetixNotificationPreferences';
import KinetixCheckbox from './KinetixCheckbox.vue';

/**
 * Drop-in notification-preferences matrix: a row per notification type and a
 * column per delivery channel, each cell a checkbox — or a dash where the
 * type isn't sent on that channel. Toggling a cell persists immediately.
 * Mount on an account / settings page.
 */
const { t } = useI18n();
const { matrix, loading, load, set } = useKinetixNotificationPreferences();

onMounted(load);
</script>

<template>
    <div class="space-y-4">
        <div class="space-y-1">
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
                                    :aria-label="`${type.label} · ${channel.label}`"
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
